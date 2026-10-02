<?php

declare(strict_types=1);

namespace Modules\Catalog\Repositories;

use Illuminate\Database\Connection;

/**
 * Persists and retrieves catalog categories, products, and series fields.
 */
final class CatalogRepository
{
    private Connection $db;

    private ?bool $hasLegacyTemplatingColumn = null;

    /**
     * Create the repository with the application's database connection.
     */
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Retrieve categories for the catalog hierarchy, including the legacy flag when available.
     *
     * @return list<array<string, mixed>>
     */
    public function getHierarchyCategories(): array
    {
        $columns = ['id', 'parent_id', 'name', 'type', 'display_order', 'typst_templating_enabled'];
        if ($this->hasLegacyTemplatingColumn()) {
            $columns[] = 'latex_templating_enabled';
        }

        return array_map(
            static fn (object $row): array => (array) $row,
            $this->db->select(
                'SELECT '.implode(', ', $columns).' FROM category ORDER BY display_order ASC, name ASC'
            )
        );
    }

    /**
     * Retrieve products used to attach product nodes to their series.
     *
     * @return list<array<string, mixed>>
     */
    public function getHierarchyProducts(): array
    {
        return array_map(
            static fn (object $row): array => (array) $row,
            $this->db->select('SELECT id, series_id, name, sku FROM product ORDER BY name ASC')
        );
    }

    /**
     * Search catalog categories by their name.
     *
     * @return list<array<string, mixed>>
     */
    public function searchCategories(string $term): array
    {
        $searchTerm = '%'.$term.'%';

        return array_map(
            static fn (object $row): array => (array) $row,
            $this->db->select('SELECT id, parent_id, name, type FROM category WHERE name LIKE ?', [$searchTerm])
        );
    }

    /**
     * Search products by their name or SKU.
     *
     * @return list<array<string, mixed>>
     */
    public function searchProducts(string $term): array
    {
        $searchTerm = '%'.$term.'%';

        return array_map(
            static fn (object $row): array => (array) $row,
            $this->db->select(
                'SELECT id, series_id, name FROM product WHERE name LIKE ? OR sku LIKE ?',
                [$searchTerm, $searchTerm]
            )
        );
    }

    /**
     * Find a series category by its id.
     *
     * @return array<string, mixed>|null
     */
    public function findSeries(int $seriesId): ?array
    {
        $series = $this->db->selectOne(
            "SELECT id, parent_id, name, type FROM category WHERE id = ? AND type = 'series'",
            [$seriesId]
        );

        return $series === null ? null : (array) $series;
    }

    /**
     * Retrieve series metadata values and labels.
     *
     * @return list<array<string, mixed>>
     */
    public function getSeriesMetadata(int $seriesId): array
    {
        return array_map(
            static fn (object $row): array => (array) $row,
            $this->db->select(
                "SELECT f.field_key, f.label, v.value
                 FROM series_custom_field f
                 LEFT JOIN series_custom_field_value v ON f.id = v.series_custom_field_id AND v.series_id = ?
                 WHERE f.series_id = ? AND f.field_scope = 'series_metadata'",
                [$seriesId, $seriesId]
            )
        );
    }

    /**
     * Retrieve product attribute field definitions for a series.
     *
     * @return list<array<string, mixed>>
     */
    public function getProductAttributeFields(int $seriesId): array
    {
        return array_map(
            static fn (object $row): array => (array) $row,
            $this->db->select(
                "SELECT field_key, label, field_type
                 FROM series_custom_field
                 WHERE series_id = ? AND field_scope = 'product_attribute'
                 ORDER BY sort_order ASC",
                [$seriesId]
            )
        );
    }

    /**
     * Return whether the legacy LaTeX flag exists, without changing the schema.
     */
    private function hasLegacyTemplatingColumn(): bool
    {
        if ($this->hasLegacyTemplatingColumn === null) {
            $this->hasLegacyTemplatingColumn = $this->hasCategoryColumn('latex_templating_enabled');
        }

        return $this->hasLegacyTemplatingColumn;
    }

    /**
     * Check whether a category table column exists.
     */
    private function hasCategoryColumn(string $column): bool
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['category', $column]
        ) > 0;
    }
}
