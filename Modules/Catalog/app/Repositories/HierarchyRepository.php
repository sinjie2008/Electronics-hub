<?php

declare(strict_types=1);

namespace Modules\Catalog\Repositories;

use Illuminate\Database\Connection;

/**
 * Persists category and series hierarchy data.
 */
final class HierarchyRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * Updates an existing category or series node.
     */
    public function updateNode(?int $parentId, string $name, string $type, int $displayOrder, int $nodeId): void
    {
        $this->connection->update(
            'UPDATE category SET parent_id = ?, name = ?, type = ?, display_order = ? WHERE id = ?',
            [$parentId, $name, $type, $displayOrder, $nodeId]
        );
    }

    /**
     * Counts direct children of a category node.
     */
    public function countChildren(int $nodeId): int
    {
        return (int) $this->connection->scalar('SELECT COUNT(1) FROM category WHERE parent_id = ?', [$nodeId]);
    }

    /**
     * Deletes one category node.
     */
    public function deleteNode(int $nodeId): void
    {
        $this->connection->delete('DELETE FROM category WHERE id = ? LIMIT 1', [$nodeId]);
    }

    /**
     * Returns category rows in their existing hierarchy display order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchHierarchyRows(bool $includeLegacy): array
    {
        return array_map(
            static fn (object $row): array => (array) $row,
            $this->connection->select(sprintf(
                'SELECT %s FROM category ORDER BY display_order, id',
                self::getCategorySelectColumns($includeLegacy)
            ))
        );
    }

    /**
     * Returns series options in their existing name order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchSeriesOptionRows(): array
    {
        return array_map(
            static fn (object $row): array => (array) $row,
            $this->connection->select("SELECT id, name FROM category WHERE type = 'series' ORDER BY name, id")
        );
    }

    /**
     * Loads one category row by its ID.
     *
     * @return array<string, mixed>|null
     */
    public function findNode(int $nodeId, bool $includeLegacy): ?array
    {
        $row = $this->connection->selectOne(
            sprintf('SELECT %s FROM category WHERE id = ? LIMIT 1', self::getCategorySelectColumns($includeLegacy)),
            [$nodeId]
        );

        return $row === null ? null : (array) $row;
    }

    /**
     * Builds the category column list with the optional legacy templating flag.
     */
    public static function getCategorySelectColumns(bool $includeLegacy): string
    {
        $columns = [
            'id',
            'parent_id',
            'name',
            'type',
            'display_order',
            'typst_templating_enabled',
        ];
        if ($includeLegacy) {
            $columns[] = 'latex_templating_enabled';
        }

        return implode(', ', $columns);
    }

    /**
     * Counts products assigned to a series.
     */
    public function countProductsForSeries(int $seriesId): int
    {
        return (int) $this->connection->scalar('SELECT COUNT(1) FROM product WHERE series_id = ?', [$seriesId]);
    }

    /**
     * Inserts a hierarchy node and returns its database ID.
     */
    public function insertNode(?int $parentId, string $name, string $type, int $displayOrder): int
    {
        return $this->insertId(
            'INSERT INTO category (parent_id, name, type, display_order) VALUES (?, ?, ?, ?)',
            [$parentId, $name, $type, $displayOrder]
        );
    }

    /**
     * Updates the templating flags for a series.
     */
    public function updateTemplatingEnabled(int $seriesId, int $flag, bool $hasLegacyColumn): void
    {
        if ($hasLegacyColumn) {
            $this->connection->update(
                "UPDATE category SET typst_templating_enabled = ?, latex_templating_enabled = ? WHERE id = ? AND type = 'series'",
                [$flag, $flag, $seriesId]
            );
        } else {
            $this->connection->update(
                "UPDATE category SET typst_templating_enabled = ? WHERE id = ? AND type = 'series'",
                [$flag, $seriesId]
            );
        }
    }

    /**
     * Checks whether a column exists on the category table.
     */
    public function hasCategoryColumn(string $column): bool
    {
        return (int) $this->connection->scalar(
            'SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['category', $column]
        ) > 0;
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
