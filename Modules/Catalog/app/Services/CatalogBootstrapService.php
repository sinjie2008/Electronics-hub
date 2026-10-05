<?php

declare(strict_types=1);

namespace Modules\Catalog\Services;

use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Catalog\Support\Config;

/** Preserve the standalone catalog's initial data and default metadata without runtime DDL. */
final class CatalogBootstrapService
{
    public function __construct(private Connection $connection) {}

    public function bootstrap(): void
    {
        $this->ensureMetadataDefaults();
        $this->seedInitialData();
    }

    public function seedInitialData(): void
    {
        $seedName = (string) Config::get('app')['seed_name'];
        if ($this->connection->table('seed_migration')->where('name', $seedName)->exists()) {
            return;
        }
        $this->connection->transaction(function () use ($seedName): void {
            foreach ($this->getSeedTree() as $index => $node) {
                $this->insertNode(null, $node, $index + 1);
            }
            $this->connection->table('seed_migration')->insert(['name' => $seedName]);
        });
    }

    private function ensureMetadataDefaults(): void
    {
        foreach ($this->connection->table('category')->where('type', 'series')->pluck('id') as $seriesId) {
            $fields = $this->connection->table('series_custom_field')
                ->where('series_id', $seriesId)->where('field_scope', SeriesFieldService::SCOPE_SERIES);
            $maxSortOrder = (int) $fields->max('sort_order');
            foreach ($this->getDefaultSeriesMetadataFieldSeeds() as $index => $definition) {
                $fieldId = (clone $fields)->where('field_key', $definition['field_key'])->value('id');
                if ($fieldId === null) {
                    try {
                        $fieldId = $this->insertField((int) $seriesId, $definition, $maxSortOrder + $index + 1, SeriesFieldService::SCOPE_SERIES);
                    } catch (UniqueConstraintViolationException $exception) {
                        $fieldId = (clone $fields)->where('field_key', $definition['field_key'])->value('id');
                        if ($fieldId === null) {
                            throw $exception;
                        }
                    }
                }
                $values = $this->connection->table('series_custom_field_value')->where('series_id', $seriesId)
                    ->where('series_custom_field_id', $fieldId);
                if (! $values->exists()) {
                    try {
                        $this->connection->table('series_custom_field_value')->insert([
                            'series_id' => $seriesId, 'series_custom_field_id' => $fieldId,
                            'value' => $definition['default_value'] ?? null,
                        ]);
                    } catch (UniqueConstraintViolationException $exception) {
                        if (! (clone $values)->exists()) {
                            throw $exception;
                        }
                    }
                }
            }
        }
    }

    /** @param array<string, mixed> $node */
    private function insertNode(?int $parentId, array $node, int $displayOrder): void
    {
        $nodeId = (int) $this->connection->table('category')->insertGetId([
            'parent_id' => $parentId, 'name' => $node['name'], 'type' => $node['type'], 'display_order' => $displayOrder,
        ]);
        if ($node['type'] === 'series') {
            $productFields = [];
            foreach ($node['fields'] ?? [] as $index => $definition) {
                $productFields[$definition['field_key']] = $this->insertField($nodeId, $definition, $index + 1, SeriesFieldService::SCOPE_PRODUCT);
            }
            foreach ($node['metadataFields'] ?? [] as $index => $definition) {
                $fieldId = $this->insertField($nodeId, $definition, $index + 1, SeriesFieldService::SCOPE_SERIES);
                $this->connection->table('series_custom_field_value')->insert([
                    'series_id' => $nodeId, 'series_custom_field_id' => $fieldId,
                    'value' => $node['metadataValues'][$definition['field_key']] ?? null,
                ]);
            }
            foreach ($node['products'] ?? [] as $product) {
                $productId = (int) $this->connection->table('product')->insertGetId([
                    'series_id' => $nodeId, 'sku' => $product['sku'], 'name' => $product['name'], 'description' => $product['description'] ?? null,
                ]);
                foreach ($product['custom_values'] ?? [] as $key => $value) {
                    if (isset($productFields[$key])) {
                        $this->connection->table('product_custom_field_value')->insert([
                            'product_id' => $productId, 'series_custom_field_id' => $productFields[$key], 'value' => $value,
                        ]);
                    }
                }
            }
        }
        foreach ($node['children'] ?? [] as $index => $child) {
            $this->insertNode($nodeId, $child, $index + 1);
        }
    }

    /** @param array<string, mixed> $definition */
    private function insertField(int $seriesId, array $definition, int $sortOrder, string $scope): int
    {
        return (int) $this->connection->table('series_custom_field')->insertGetId([
            'series_id' => $seriesId, 'field_key' => $definition['field_key'], 'label' => $definition['label'],
            'field_type' => $definition['field_type'] ?? 'text', 'field_scope' => $scope,
            'default_value' => $definition['default_value'] ?? null, 'sort_order' => $sortOrder,
            'is_required' => (int) ($definition['is_required'] ?? false),
            'is_public_portal_hidden' => (int) ($definition['is_public_portal_hidden'] ?? false),
            'is_backend_portal_hidden' => (int) ($definition['is_backend_portal_hidden'] ?? false),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function getSeedTree(): array
    {
        return [
            [
                'name' => 'General Products',
                'type' => 'category',
                'children' => [
                    $this->buildSeriesSeed(
                        'C0 SERIES',
                        [
                            'metadata_values' => [
                                'series_voltage' => '16V - 25V',
                                'series_notes' => 'General-purpose capacitor line.',
                            ],
                            'products' => [
                                [
                                    'sku' => 'C0-100',
                                    'name' => 'Capacitor 100uF',
                                    'description' => 'Compact capacitor suitable for general electronics.',
                                    'custom_values' => [
                                        'voltage_rating' => '16V',
                                        'tolerance' => '+/-10%',
                                    ],
                                ],
                                [
                                    'sku' => 'C0-200',
                                    'name' => 'Capacitor 220uF',
                                    'description' => 'High capacity for power supplies.',
                                    'custom_values' => [
                                        'voltage_rating' => '25V',
                                        'tolerance' => '+/-20%',
                                    ],
                                ],
                            ],
                        ]
                    ),
                    $this->buildSeriesSeed(
                        'C1 SERIES',
                        [
                            'metadata_values' => [
                                'series_voltage' => '35V',
                                'series_notes' => 'Low ESR line for audio applications.',
                            ],
                            'products' => [
                                [
                                    'sku' => 'C1-300',
                                    'name' => 'Capacitor 330uF',
                                    'description' => 'Low ESR capacitor for audio applications.',
                                    'custom_values' => [
                                        'voltage_rating' => '35V',
                                        'tolerance' => '+/-5%',
                                    ],
                                ],
                            ],
                        ]
                    ),
                ],
            ],
            [
                'name' => 'EMC Components',
                'type' => 'category',
                'children' => [
                    $this->buildSeriesSeed(
                        'EM-Filter',
                        [
                            'metadata_values' => [
                                'series_voltage' => '250VAC',
                                'series_notes' => 'Electromagnetic interference suppression filters.',
                            ],
                            'products' => [
                                [
                                    'sku' => 'EM-F-01',
                                    'name' => 'Power Line Filter',
                                    'description' => 'Suppresses conducted emissions.',
                                    'custom_values' => [
                                        'voltage_rating' => '250VAC',
                                        'tolerance' => 'Standard',
                                    ],
                                ],
                            ],
                        ]
                    ),
                ],
            ],
        ];
    }

    /**
     * Helper for building a series node within seed data.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function buildSeriesSeed(string $seriesName, array $definition): array
    {
        $products = $definition['products'] ?? [];
        $productFields = $definition['product_fields']
            ?? $definition['fields']
            ?? $this->getDefaultProductFieldSeeds();
        $metadataFields = $definition['metadata_fields'] ?? $this->getDefaultSeriesMetadataFieldSeeds();
        $metadataValues = $definition['metadata_values'] ?? [];

        return [
            'name' => $seriesName,
            'type' => 'series',
            'fields' => $productFields,
            'metadataFields' => $metadataFields,
            'metadataValues' => $metadataValues,
            'products' => $products,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getDefaultProductFieldSeeds(): array
    {
        return [
            [
                'field_key' => 'voltage_rating',
                'label' => 'Voltage Rating',
                'field_type' => 'text',
                'is_required' => false,
            ],
            [
                'field_key' => 'tolerance',
                'label' => 'Tolerance',
                'field_type' => 'text',
                'is_required' => false,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getDefaultSeriesMetadataFieldSeeds(): array
    {
        return [
            [
                'field_key' => 'series_voltage',
                'label' => 'Voltage Range',
                'field_type' => 'text',
                'is_required' => false,
            ],
            [
                'field_key' => 'series_notes',
                'label' => 'Series Notes',
                'field_type' => 'text',
                'is_required' => false,
            ],
        ];
    }
}
