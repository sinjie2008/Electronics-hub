<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Concerns;

use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;

trait HasCatalogDynamicFields
{
    /**
     * @param  array<int|string, array<string, mixed>>  $definitions
     * @return array<int, TextInput>
     */
    protected static function dynamicValueInputs(array $definitions, string $statePrefix): array
    {
        $components = [];

        foreach ($definitions as $definition) {
            $fieldId = (int) ($definition['id'] ?? 0);
            if ($fieldId <= 0) {
                continue;
            }

            $label = (string) (
                $definition['label']
                ?? $definition['fieldKey']
                ?? $definition['field_key']
                ?? "Field {$fieldId}"
            );
            $fieldType = strtolower((string) ($definition['fieldType'] ?? $definition['field_type'] ?? 'text'));
            $isRequired = (bool) ($definition['isRequired'] ?? $definition['is_required'] ?? false);
            $defaultValue = $definition['defaultValue'] ?? $definition['default_value'] ?? null;
            $field = TextInput::make($statePrefix.'.'.$fieldId)
                ->label($label);

            if ($fieldType === 'file') {
                $components[] = $field
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('File uploads remain available in the Catalog legacy interface.');

                continue;
            }

            if ($fieldType === 'number') {
                $field->numeric();
            } else {
                $field->maxLength(16000);
            }

            if ($isRequired) {
                $field->required();
            }

            if ($defaultValue !== null) {
                $field->default($defaultValue);
            }

            $components[] = $field;
        }

        return $components;
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $definitions
     * @param  array<int|string, mixed>  $values
     * @return array<int, TextEntry>
     */
    protected static function dynamicValueEntries(array $definitions, array $values): array
    {
        $components = [];

        foreach ($definitions as $definition) {
            $fieldId = (int) ($definition['id'] ?? 0);
            if ($fieldId <= 0) {
                continue;
            }

            $components[] = TextEntry::make('catalog_value_'.$fieldId)
                ->label((string) (
                    $definition['label']
                    ?? $definition['fieldKey']
                    ?? $definition['field_key']
                    ?? "Field {$fieldId}"
                ))
                ->state($values[$fieldId] ?? $values[(string) $fieldId] ?? null);
        }

        return $components;
    }
}
