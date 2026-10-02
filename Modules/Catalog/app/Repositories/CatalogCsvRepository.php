<?php

declare(strict_types=1);

namespace Modules\Catalog\Repositories;

use Illuminate\Database\Connection;
use Modules\Catalog\Support\Config;

/**
 * Reads and persists catalog data used by CSV import and export workflows.
 */
final class CatalogCsvRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * Finds an existing category with the given parent and exact name.
     *
     * @return array<string, mixed>|null
     */
    public function findCategory(?int $parentId, string $name): ?array
    {
        return $this->selectOne(
            "SELECT id FROM category WHERE parent_id <=> ? AND name = ? AND type = 'category' LIMIT 1",
            [$parentId, $name]
        );
    }

    /**
     * Inserts a category node and returns its ID.
     */
    public function insertCategory(?int $parentId, string $name): int
    {
        return $this->insertId(
            "INSERT INTO category (parent_id, name, type, display_order) VALUES (?, ?, 'category', 0)",
            [$parentId, $name]
        );
    }

    /**
     * Finds an existing series with the given parent and exact name.
     *
     * @return array<string, mixed>|null
     */
    public function findSeries(?int $parentId, string $name): ?array
    {
        return $this->selectOne(
            "SELECT id, display_order FROM category WHERE parent_id <=> ? AND name = ? AND type = 'series' LIMIT 1",
            [$parentId, $name]
        );
    }

    /**
     * Updates a series display order.
     */
    public function updateSeriesDisplayOrder(int $seriesId, int $displayOrder): void
    {
        $this->connection->update('UPDATE category SET display_order = ? WHERE id = ?', [$displayOrder, $seriesId]);
    }

    /**
     * Inserts a series node and returns its ID.
     */
    public function insertSeries(?int $parentId, string $name, int $displayOrder): int
    {
        return $this->insertId(
            "INSERT INTO category (parent_id, name, type, display_order) VALUES (?, ?, 'series', ?)",
            [$parentId, $name, $displayOrder]
        );
    }

    /**
     * Finds a product by its series and SKU.
     *
     * @return array<string, mixed>|null
     */
    public function findProductBySku(int $seriesId, string $sku): ?array
    {
        return $this->selectOne('SELECT id FROM product WHERE series_id = ? AND sku = ? LIMIT 1', [$seriesId, $sku]);
    }

    /**
     * Updates imported product data.
     */
    public function updateProductFromImport(string $name, ?string $description, int $productId): void
    {
        $this->connection->update(
            'UPDATE product SET name = ?, description = ? WHERE id = ?',
            [$name, $description, $productId]
        );
    }

    /**
     * Inserts an imported product and returns its ID.
     */
    public function insertProduct(int $seriesId, string $sku, string $name, ?string $description): int
    {
        return $this->insertId(
            'INSERT INTO product (series_id, sku, name, description) VALUES (?, ?, ?, ?)',
            [$seriesId, $sku, $name, $description]
        );
    }

    /**
     * Replaces custom values from a CSV row while preserving field iteration and statement reuse.
     *
     * @param  array<string, mixed>  $customValues
     * @param  array<string, array<string, mixed>>  $seriesFieldMap
     */
    public function replaceProductCustomValues(
        int $productId,
        array $customValues,
        array $seriesFieldMap
    ): void {
        $this->connection->delete('DELETE FROM product_custom_field_value WHERE product_id = ?', [$productId]);

        if ($customValues === []) {
            return;
        }

        foreach ($customValues as $fieldKey => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            if (! isset($seriesFieldMap[$fieldKey])) {
                continue;
            }
            $fieldId = (int) $seriesFieldMap[$fieldKey]['id'];
            $this->connection->insert(
                'INSERT INTO product_custom_field_value (product_id, series_custom_field_id, value) VALUES (?, ?, ?)',
                [$productId, $fieldId, $value]
            );
        }
    }

    /**
     * Returns all product IDs.
     *
     * @return array<int, int>
     */
    public function fetchProductIds(): array
    {
        return array_map(
            static fn (object $row): int => (int) $row->id,
            $this->connection->select('SELECT id FROM product')
        );
    }

    /**
     * Returns all series IDs.
     *
     * @return array<int, int>
     */
    public function fetchSeriesIds(): array
    {
        return array_map(
            static fn (object $row): int => (int) $row->id,
            $this->connection->select("SELECT id FROM category WHERE type = 'series'")
        );
    }

    /**
     * Returns all category IDs.
     *
     * @return array<int, int>
     */
    public function fetchCategoryIds(): array
    {
        return array_map(
            static fn (object $row): int => (int) $row->id,
            $this->connection->select("SELECT id FROM category WHERE type = 'category'")
        );
    }

    /**
     * Deletes products with the given IDs.
     *
     * @param  array<int>  $ids
     */
    public function deleteProducts(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->connection->delete("DELETE FROM product WHERE id IN ($placeholders)", array_map('intval', $ids));
    }

    /**
     * Deletes series nodes with the given IDs.
     *
     * @param  array<int>  $ids
     */
    public function deleteSeries(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->connection->delete(
            "DELETE FROM category WHERE type = 'series' AND id IN ($placeholders)",
            array_map('intval', $ids)
        );
    }

    /**
     * Deletes category nodes with the given IDs.
     *
     * @param  array<int>  $ids
     */
    public function deleteCategories(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->connection->delete(
            "DELETE FROM category WHERE type = 'category' AND id IN ($placeholders)",
            array_map('intval', $ids)
        );
    }

    /**
     * Returns raw category rows for CSV path construction.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchCategoryRows(): array
    {
        return $this->selectAll('SELECT id, parent_id, name, type, display_order FROM category');
    }

    /**
     * Returns custom field keys ordered by their configured order.
     *
     * @return array<int, string>
     */
    public function fetchCustomFieldKeys(string $scope): array
    {
        $rows = $this->connection->select(
            'SELECT field_key, MIN(sort_order) AS sort_order
             FROM series_custom_field
             WHERE field_scope = ?
             GROUP BY field_key
             ORDER BY sort_order, field_key',
            [$scope]
        );

        return array_map(static fn (object $row): string => (string) $row->field_key, $rows);
    }

    /**
     * Returns rows for the CSV product export.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchProductsWithSeriesRows(): array
    {
        $sql = 'SELECT p.id, p.series_id, p.sku, p.name, p.description, s.name AS series_name, s.display_order AS series_display_order
                FROM product p
                INNER JOIN category s ON s.id = p.series_id
                ORDER BY s.name, p.sku';

        return $this->selectAll($sql);
    }

    /**
     * Returns custom value rows for the CSV product export.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchProductCustomValueRows(): array
    {
        $sql = 'SELECT pcv.product_id, scf.field_key, pcv.value
                FROM product_custom_field_value pcv
                INNER JOIN series_custom_field scf ON scf.id = pcv.series_custom_field_id';

        return $this->selectAll($sql);
    }

    /**
     * Checks whether the catalog truncate advisory lock is held.
     */
    public function isTruncateInProgress(): bool
    {
        $lockKey = Config::get('app')['truncate']['lock_key'];
        $row = $this->connection->selectOne('SELECT IS_USED_LOCK(?) AS lock_owner', [$lockKey]);

        return ($row->lock_owner ?? null) !== null;
    }

    /**
     * Executes a bound insert and returns the generated row ID.
     *
     * @param  list<mixed>  $bindings
     */
    private function insertId(string $sql, array $bindings): int
    {
        if (! $this->connection->insert($sql, $bindings)) {
            throw new \RuntimeException('Failed to execute insert query.');
        }

        return (int) $this->connection->getPdo()->lastInsertId();
    }

    /**
     * Fetches an optional repository-owned row.
     *
     * @return array<string, mixed>|null
     */
    private function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = $this->connection->selectOne($sql, $bindings);

        return $row === null ? null : (array) $row;
    }

    /**
     * Fetches rows for a repository-owned query.
     *
     * @return array<int, array<string, mixed>>
     */
    private function selectAll(string $sql, array $bindings = []): array
    {
        return array_map(
            static fn (object $row): array => (array) $row,
            $this->connection->select($sql, $bindings)
        );
    }
}
