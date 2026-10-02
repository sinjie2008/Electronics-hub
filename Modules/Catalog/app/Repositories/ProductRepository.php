<?php

declare(strict_types=1);

namespace Modules\Catalog\Repositories;

use Illuminate\Database\Connection;

/**
 * Reads and persists products and their custom field values.
 */
final class ProductRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * Returns products for a set of series in their existing order.
     *
     * @param  array<int>  $seriesIds
     * @return array<int, array<string, mixed>>
     */
    public function fetchProductsForSeriesIds(array $seriesIds): array
    {
        if ($seriesIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($seriesIds), '?'));

        return array_map(
            static fn (object $row): array => (array) $row,
            $this->connection->select(
                sprintf(
                    'SELECT id, series_id, sku, name, description
                     FROM product
                     WHERE series_id IN (%s)
                     ORDER BY series_id, id',
                    $placeholders
                ),
                array_map('intval', $seriesIds)
            )
        );
    }

    /**
     * Updates a product in its series.
     */
    public function updateProduct(string $sku, string $name, ?string $description, int $productId, int $seriesId): void
    {
        $this->connection->update(
            'UPDATE product SET sku = ?, name = ?, description = ? WHERE id = ? AND series_id = ?',
            [$sku, $name, $description, $productId, $seriesId]
        );
    }

    /**
     * Inserts a product and returns its ID.
     */
    public function insertProduct(int $seriesId, string $sku, string $name, ?string $description): int
    {
        return $this->insertId(
            'INSERT INTO product (series_id, sku, name, description) VALUES (?, ?, ?, ?)',
            [$seriesId, $sku, $name, $description]
        );
    }

    /**
     * Replaces all custom values for a product by removing its current rows.
     */
    public function deleteCustomValues(int $productId): void
    {
        $this->connection->delete('DELETE FROM product_custom_field_value WHERE product_id = ?', [$productId]);
    }

    /**
     * Inserts one product custom value.
     */
    public function insertCustomValue(int $productId, int $fieldId, string $value): void
    {
        $this->connection->insert(
            'INSERT INTO product_custom_field_value (product_id, series_custom_field_id, value) VALUES (?, ?, ?)',
            [$productId, $fieldId, $value]
        );
    }

    /**
     * Deletes one product row.
     */
    public function deleteProduct(int $productId): void
    {
        $this->connection->delete('DELETE FROM product WHERE id = ? LIMIT 1', [$productId]);
    }

    /**
     * Returns products for one series in their existing order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchProductsForSeries(int $seriesId): array
    {
        return array_map(
            static fn (object $row): array => (array) $row,
            $this->connection->select(
                'SELECT id, sku, name, description FROM product WHERE series_id = ? ORDER BY name, id',
                [$seriesId]
            )
        );
    }

    /**
     * Loads a product row by ID.
     *
     * @return array<string, mixed>|null
     */
    public function findProduct(int $productId): ?array
    {
        $row = $this->connection->selectOne(
            'SELECT id, series_id, sku, name, description FROM product WHERE id = ? LIMIT 1',
            [$productId]
        );

        return $row === null ? null : (array) $row;
    }

    /**
     * Returns custom value rows for products.
     *
     * @param  array<int>  $productIds
     * @return array<int, array<string, mixed>>
     */
    public function fetchCustomValueRows(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));

        return array_map(
            static fn (object $row): array => (array) $row,
            $this->connection->select(
                sprintf(
                    'SELECT product_id, series_custom_field_id, value
                     FROM product_custom_field_value
                     WHERE product_id IN (%s)',
                    $placeholders
                ),
                array_map('intval', $productIds)
            )
        );
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
}
