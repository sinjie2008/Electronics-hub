<?php

declare(strict_types=1);

namespace Modules\Catalog\Repositories;

use Illuminate\Database\Connection;

/**
 * Persists and reads custom field definitions for catalog series.
 */
final class SeriesFieldRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * Returns field rows for a series and scope.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchFields(int $seriesId, string $fieldScope): array
    {
        return array_map(
            static fn (object $row): array => (array) $row,
            $this->connection->select(
                'SELECT id, field_key, label, field_type, field_scope, default_value, sort_order, is_required,
                        is_public_portal_hidden, is_backend_portal_hidden
                 FROM series_custom_field
                 WHERE series_id = ? AND field_scope = ?
                 ORDER BY sort_order, id',
                [$seriesId, $fieldScope]
            )
        );
    }

    /**
     * Updates a custom field definition.
     */
    public function updateField(
        string $label,
        string $fieldKey,
        string $fieldType,
        ?string $defaultValue,
        int $sortOrder,
        int $required,
        int $publicPortalHidden,
        int $backendPortalHidden,
        int $fieldId,
        int $seriesId
    ): void {
        $this->connection->update(
            'UPDATE series_custom_field
             SET label = ?, field_key = ?, field_type = ?, default_value = ?, sort_order = ?, is_required = ?,
                 is_public_portal_hidden = ?, is_backend_portal_hidden = ?
             WHERE id = ? AND series_id = ?',
            [
                $label,
                $fieldKey,
                $fieldType,
                $defaultValue,
                $sortOrder,
                $required,
                $publicPortalHidden,
                $backendPortalHidden,
                $fieldId,
                $seriesId,
            ]
        );
    }

    /**
     * Inserts a custom field definition and returns its ID.
     */
    public function insertField(
        int $seriesId,
        string $fieldKey,
        string $label,
        string $fieldType,
        string $fieldScope,
        ?string $defaultValue,
        int $sortOrder,
        int $required,
        int $publicPortalHidden,
        int $backendPortalHidden
    ): int {
        return $this->insertId(
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
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $seriesId,
                $fieldKey,
                $label,
                $fieldType,
                $fieldScope,
                $defaultValue,
                $sortOrder,
                $required,
                $publicPortalHidden,
                $backendPortalHidden,
            ]
        );
    }

    /**
     * Checks whether a custom field exists.
     */
    public function fieldExists(int $fieldId): bool
    {
        return $this->connection->selectOne(
            'SELECT id FROM series_custom_field WHERE id = ? LIMIT 1',
            [$fieldId]
        ) !== null;
    }

    /**
     * Deletes a custom field definition.
     */
    public function deleteField(int $fieldId): void
    {
        $this->connection->delete('DELETE FROM series_custom_field WHERE id = ? LIMIT 1', [$fieldId]);
    }

    /**
     * Checks whether a row is a series node.
     */
    public function seriesExists(int $seriesId): bool
    {
        return $this->connection->selectOne(
            "SELECT id FROM category WHERE id = ? AND type = 'series' LIMIT 1",
            [$seriesId]
        ) !== null;
    }

    /**
     * Loads the series and scope associated with a custom field.
     *
     * @return array<string, mixed>|null
     */
    public function findField(int $fieldId): ?array
    {
        $field = $this->connection->selectOne(
            'SELECT id, series_id, field_scope FROM series_custom_field WHERE id = ? LIMIT 1',
            [$fieldId]
        );

        return $field === null ? null : (array) $field;
    }

    /**
     * Counts matching field keys, optionally excluding an existing field.
     */
    public function countFieldKey(int $seriesId, string $fieldScope, string $fieldKey, ?int $excludeId): int
    {
        $sql = 'SELECT COUNT(1) FROM series_custom_field WHERE series_id = ? AND field_scope = ? AND field_key = ?';
        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
        }

        $bindings = [$seriesId, $fieldScope, $fieldKey];
        if ($excludeId !== null) {
            $bindings[] = $excludeId;
        }

        return (int) $this->connection->scalar($sql, $bindings);
    }

    /**
     * Returns field rows for multiple series and a scope.
     *
     * @param  array<int>  $seriesIds
     * @return array<int, array<string, mixed>>
     */
    public function fetchFieldsForSeriesIds(array $seriesIds, string $fieldScope): array
    {
        if ($seriesIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($seriesIds), '?'));

        return array_map(
            static fn (object $row): array => (array) $row,
            $this->connection->select(
                sprintf(
                    'SELECT id, series_id, field_key, label, field_type, field_scope, default_value, sort_order, is_required,
                            is_public_portal_hidden, is_backend_portal_hidden
                     FROM series_custom_field
                     WHERE series_id IN (%s) AND field_scope = ?
                     ORDER BY sort_order, id',
                    $placeholders
                ),
                [...array_map('intval', $seriesIds), $fieldScope]
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
