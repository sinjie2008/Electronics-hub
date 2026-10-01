<?php

declare(strict_types=1);

namespace Modules\System\Filament\Pages;

use DateTimeZone;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Modules\System\Services\SettingsManager;

/**
 * @property-read Schema $form
 */
final class SystemSettingsPage extends Page
{
    protected static ?string $slug = 'system/settings';

    protected static ?string $title = 'System Settings';

    protected static ?string $navigationLabel = 'Settings';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::AdjustmentsHorizontal;

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 50;

    protected string $view = 'system::filament.pages.settings';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.view') ?? false;
    }

    public function mount(SettingsManager $settingsManager): void
    {
        Gate::authorize('settings.view');

        $this->form->fill($settingsManager->current()->toArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('application_name')
                    ->label('Application name')
                    ->required()
                    ->maxLength(255)
                    ->disabled(fn (): bool => Gate::denies('settings.update')),
                Textarea::make('application_description')
                    ->label('Application description')
                    ->required()
                    ->maxLength(1000)
                    ->rows(3)
                    ->columnSpanFull()
                    ->disabled(fn (): bool => Gate::denies('settings.update')),
                Select::make('timezone')
                    ->options($this->timezoneOptions())
                    ->searchable()
                    ->required()
                    ->disabled(fn (): bool => Gate::denies('settings.update')),
                Select::make('default_locale')
                    ->options($this->localeOptions())
                    ->required()
                    ->disabled(fn (): bool => Gate::denies('settings.update')),
                TextInput::make('pagination_size')
                    ->label('Pagination size')
                    ->numeric()
                    ->integer()
                    ->minValue(5)
                    ->maxValue(100)
                    ->required()
                    ->disabled(fn (): bool => Gate::denies('settings.update')),
                Select::make('default_ai_provider')
                    ->options([
                        'openai' => 'OpenAI',
                        'anthropic' => 'Anthropic',
                        'gemini' => 'Gemini',
                    ])
                    ->required()
                    ->disabled(fn (): bool => Gate::denies('settings.update')),
                TextInput::make('default_ai_model')
                    ->label('Default AI model')
                    ->maxLength(100)
                    ->disabled(fn (): bool => Gate::denies('settings.update')),
            ])
            ->columns(2)
            ->statePath('data');
    }

    public function save(SettingsManager $settingsManager): void
    {
        Gate::authorize('settings.view');
        Gate::authorize('settings.update');

        $settingsManager->save($this->form->getState());

        Notification::make()
            ->title('System settings saved')
            ->success()
            ->send();
    }

    /**
     * @return array<string, string>
     */
    private function timezoneOptions(): array
    {
        $timezones = array_values(array_unique([
            ...DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),
            'UTC',
        ]));

        return array_combine($timezones, $timezones);
    }

    /**
     * @return array<string, string>
     */
    private function localeOptions(): array
    {
        $locales = config('enterprise.locales', ['en']);

        if (! is_array($locales) || $locales === []) {
            return ['en' => 'en'];
        }

        if (array_is_list($locales)) {
            return array_combine($locales, $locales);
        }

        return $locales;
    }
}
