<?php

declare(strict_types=1);

namespace Modules\Catalog\Support;

use Modules\Catalog\Services\SeriesFieldService;
use mysqli;
use Throwable;

final class Seeder
{
    public function __construct(private mysqli $connection) {}

    /**
     * Applies the initial seed if it has not been executed.
     */
    public function seedInitialData(): void
    {
        if ($this->isSeedApplied(Config::get('app')['seed_name'])) {
            return;
        }

        $this->connection->begin_transaction();

        try {
            $tree = $this->getSeedTree();
            $seriesFieldCache = [];
            foreach ($tree as $index => $node) {
                $this->insertNodeRecursive(null, $node, $index + 1, $seriesFieldCache);
            }

            $stmt = $this->connection->prepare('INSERT INTO seed_migration (name) VALUES (?)');
            $seedName = Config::get('app')['seed_name'];
            $stmt->bind_param('s', $seedName);
            $stmt->execute();
            $stmt->close();

            $this->connection->commit();
        } catch (Throwable $exception) {
            $this->connection->rollback();
            throw $exception;
        }
    }

    private function isSeedApplied(string $seedName): bool
    {
        $stmt = $this->connection->prepare('SELECT COUNT(1) AS total FROM seed_migration WHERE name = ?');
        $stmt->bind_param('s', $seedName);
        $stmt->execute();
        $result = $stmt->get_result();
        $count = (int) ($result->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        return $count > 0;
    }

    /**
     * Inserts hierarchy nodes recursively.
     *
     * @param  array<string, mixed>  $node
     * @param  array<int, array<string, array<string, int>>>  $seriesFieldCache
     */
    private function insertNodeRecursive(
        ?int $parentId,
        array $node,
        int $displayOrder,
        array &$seriesFieldCache
    ): void {
        $nodeId = $this->insertCategoryNode(
            $parentId,
            (string) $node['name'],
            (string) $node['type'],
            $displayOrder
        );

        if ($node['type'] === 'series') {
            $seriesFieldCache[$nodeId] = [
                SeriesFieldService::SCOPE_PRODUCT => [],
                SeriesFieldService::SCOPE_SERIES => [],
            ];

            $productFieldMap = &$seriesFieldCache[$nodeId][SeriesFieldService::SCOPE_PRODUCT];
            foreach ($node['fields'] ?? [] as $fieldIndex => $fieldDefinition) {
                $fieldId = $this->insertSeriesField(
                    $nodeId,
                    $fieldDefinition,
                    $fieldIndex + 1,
                    SeriesFieldService::SCOPE_PRODUCT
                );
                $productFieldMap[$fieldDefinition['field_key']] = $fieldId;
            }

            $metadataFieldMap = &$seriesFieldCache[$nodeId][SeriesFieldService::SCOPE_SERIES];
            foreach ($node['metadataFields'] ?? [] as $metaIndex => $metadataDefinition) {
                $fieldId = $this->insertSeriesField(
                    $nodeId,
                    $metadataDefinition,
                    $metaIndex + 1,
                    SeriesFieldService::SCOPE_SERIES
                );
                $metadataFieldMap[$metadataDefinition['field_key']] = $fieldId;
            }

            foreach ($node['metadataValues'] ?? [] as $metaKey => $metaValue) {
                $fieldId = $metadataFieldMap[$metaKey] ?? null;
                if ($fieldId !== null) {
                    $this->insertSeriesMetadataValue(
                        $nodeId,
                        $fieldId,
                        $metaValue !== null ? (string) $metaValue : null
                    );
                }
            }

            foreach ($node['products'] ?? [] as $productDefinition) {
                $productId = $this->insertProduct($nodeId, $productDefinition);
                foreach ($productDefinition['custom_values'] ?? [] as $fieldKey => $fieldValue) {
                    $fieldId = $productFieldMap[$fieldKey] ?? null;
                    if ($fieldId !== null) {
                        $this->insertProductCustomValue($productId, $fieldId, $fieldValue);
                    }
                }
            }
        }

        foreach ($node['children'] ?? [] as $childIndex => $childNode) {
            $this->insertNodeRecursive($nodeId, $childNode, $childIndex + 1, $seriesFieldCache);
        }
    }

    private function insertCategoryNode(
        ?int $parentId,
        string $name,
        string $type,
        int $displayOrder
    ): int {
        $stmt = $this->connection->prepare(
            'INSERT INTO category (parent_id, name, type, display_order) VALUES (?, ?, ?, ?)'
        );
        $stmt->bind_param('issi', $parentId, $name, $type, $displayOrder);
        $stmt->execute();
        $newId = (int) $stmt->insert_id;
        $stmt->close();

        return $newId;
    }

    /**
     * @param  array<string, mixed>  $fieldDefinition
     */
    private function insertSeriesField(
        int $seriesId,
        array $fieldDefinition,
        int $sortOrder,
        string $fieldScope = SeriesFieldService::SCOPE_PRODUCT
    ): int {
        $stmt = $this->connection->prepare(
            'INSERT INTO series_custom_field (
                series_id,
                field_key,
                label,
                field_type,
                field_scope,
                default_value,
                sort_order,
                is_required,
                is_public_portal_hidden,
                is_backend_portal_hidden
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $fieldKey = (string) $fieldDefinition['field_key'];
        $label = (string) $fieldDefinition['label'];
        $fieldType = (string) ($fieldDefinition['field_type'] ?? 'text');
        $normalizedScope = $this->normalizeFieldScope($fieldDefinition['field_scope'] ?? $fieldScope);
        $defaultValue = array_key_exists('default_value', $fieldDefinition)
            ? ($fieldDefinition['default_value'] !== null ? (string) $fieldDefinition['default_value'] : null)
            : null;
        $isRequired = (bool) ($fieldDefinition['is_required'] ?? false);
        $requiredValue = $isRequired ? 1 : 0;
        $isPublicPortalHidden = (bool) ($fieldDefinition['is_public_portal_hidden'] ?? false);
        $publicPortalHiddenValue = $isPublicPortalHidden ? 1 : 0;
        $isBackendPortalHidden = (bool) ($fieldDefinition['is_backend_portal_hidden'] ?? false);
        $backendPortalHiddenValue = $isBackendPortalHidden ? 1 : 0;

        $stmt->bind_param(
            'isssssiiii',
            $seriesId,
            $fieldKey,
            $label,
            $fieldType,
            $normalizedScope,
            $defaultValue,
            $sortOrder,
            $requiredValue,
            $publicPortalHiddenValue,
            $backendPortalHiddenValue
        );
        $stmt->execute();
        $insertId = (int) $stmt->insert_id;
        $stmt->close();

        return $insertId;
    }

    /**
     * @param  array<string, mixed>  $productDefinition
     */
    private function insertProduct(int $seriesId, array $productDefinition): int
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO product (series_id, sku, name, description) VALUES (?, ?, ?, ?)'
        );
        $sku = (string) $productDefinition['sku'];
        $name = (string) $productDefinition['name'];
        $description = isset($productDefinition['description'])
            ? (string) $productDefinition['description']
            : null;
        $stmt->bind_param('isss', $seriesId, $sku, $name, $description);
        $stmt->execute();
        $productId = (int) $stmt->insert_id;
        $stmt->close();

        return $productId;
    }

    private function insertProductCustomValue(int $productId, int $fieldId, ?string $value): void
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO product_custom_field_value (product_id, series_custom_field_id, value)
             VALUES (?, ?, ?)'
        );
        $stmt->bind_param('iis', $productId, $fieldId, $value);
        $stmt->execute();
        $stmt->close();
    }

    private function insertSeriesMetadataValue(int $seriesId, int $fieldId, ?string $value): void
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO series_custom_field_value (series_id, series_custom_field_id, value)
             VALUES (?, ?, ?)'
        );
        $stmt->bind_param('iis', $seriesId, $fieldId, $value);
        $stmt->execute();
        $stmt->close();
    }

    private function normalizeFieldScope(?string $scope): string
    {
        $normalized = $scope !== null ? (string) $scope : '';
        $allowedScopes = [SeriesFieldService::SCOPE_PRODUCT, SeriesFieldService::SCOPE_SERIES];

        return in_array($normalized, $allowedScopes, true)
            ? $normalized
            : SeriesFieldService::SCOPE_PRODUCT;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
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
