<?php

declare(strict_types=1);

namespace Modules\System\Services;

use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\System\Settings\SystemSettings;

final class SettingsManager
{
    /**
     * @var list<string>
     */
    private const EDITABLE_FIELDS = [
        'application_name',
        'application_description',
        'timezone',
        'default_locale',
        'pagination_size',
        'default_ai_provider',
        'default_ai_model',
    ];

    public function __construct(private SystemSettings $settings) {}

    public function current(): SystemSettings
    {
        $this->settings->refresh();

        return $this->settings;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function save(array $input): SystemSettings
    {
        Gate::authorize('settings.update');

        $this->rejectUnknownFields($input);

        $validated = Validator::make($input, [
            'application_name' => ['required', 'string', 'max:255'],
            'application_description' => ['required', 'string', 'max:1000'],
            'timezone' => ['required', 'string', 'max:255', Rule::in($this->timezones())],
            'default_locale' => ['required', 'string', Rule::in($this->supportedLocales())],
            'pagination_size' => ['required', 'integer', 'between:5,100'],
            'default_ai_provider' => ['required', 'string', Rule::in(['openai', 'anthropic', 'gemini'])],
            'default_ai_model' => ['sometimes', 'nullable', 'string', 'max:100'],
        ])->validate();

        if (array_key_exists('pagination_size', $validated)) {
            $validated['pagination_size'] = (int) $validated['pagination_size'];
        }

        if (array_key_exists('default_ai_model', $validated) && $validated['default_ai_model'] === null) {
            $validated['default_ai_model'] = '';
        }

        DB::transaction(function () use ($validated): void {
            $settings = $this->settings->refresh();
            $oldValues = array_intersect_key($settings->toArray(), $validated);

            $settings->fill($validated)->save();

            $newValues = array_intersect_key($settings->toArray(), $validated);
            $changedKeys = array_keys(array_filter(
                $newValues,
                fn (mixed $value, string $key): bool => $oldValues[$key] !== $value,
                ARRAY_FILTER_USE_BOTH,
            ));

            if ($changedKeys !== []) {
                $changedFields = array_fill_keys($changedKeys, true);

                activity('administration')
                    ->event('settings.updated')
                    ->withProperties([
                        'old' => array_intersect_key($oldValues, $changedFields),
                        'new' => array_intersect_key($newValues, $changedFields),
                    ])
                    ->log('System settings updated');
            }

        });

        return $this->settings;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function rejectUnknownFields(array $input): void
    {
        $unknownFields = array_diff(array_map(
            static fn (int|string $field): string => (string) $field,
            array_keys($input),
        ), self::EDITABLE_FIELDS);

        if ($unknownFields === []) {
            return;
        }

        $errors = [];

        foreach ($unknownFields as $field) {
            $errors[$field] = 'This setting cannot be changed.';
        }

        throw ValidationException::withMessages($errors);
    }

    /**
     * @return list<string>
     */
    private function supportedLocales(): array
    {
        $locales = config('enterprise.locales', ['en']);

        if (! is_array($locales) || $locales === []) {
            return ['en'];
        }

        $supported = array_is_list($locales) ? $locales : array_keys($locales);

        return array_values(array_filter($supported, static fn (mixed $locale): bool => is_string($locale) && $locale !== '')) ?: ['en'];
    }

    /**
     * @return list<string>
     */
    private function timezones(): array
    {
        return array_values(array_unique([
            ...DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),
            'UTC',
        ]));
    }
}
