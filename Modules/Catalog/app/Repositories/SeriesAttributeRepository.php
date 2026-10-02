<?php

declare(strict_types=1);

namespace Modules\Catalog\Repositories;

use Illuminate\Database\Connection;

/**
 * Persists series custom field values.
 */
final class SeriesAttributeRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * Returns value rows for the requested series IDs.
     *
     * @param  array<int>  $seriesIds
     * @return array<int, array<string, mixed>>
     */
    public function fetchValueRows(array $seriesIds): array
    {
        if ($seriesIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($seriesIds), '?'));

        return array_map(
            static fn (object $row): array => (array) $row,
            $this->connection->select(
                sprintf(
                    'SELECT series_id, series_custom_field_id, value
                     FROM series_custom_field_value
                     WHERE series_id IN (%s)',
                    $placeholders
                ),
                array_map('intval', $seriesIds)
            )
        );
    }

    /**
     * Inserts or updates one series custom value.
     */
    public function upsertValue(int $seriesId, int $fieldId, ?string $value): void
    {
        $this->connection->insert(
            'INSERT INTO series_custom_field_value (series_id, series_custom_field_id, value)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = CURRENT_TIMESTAMP',
            [$seriesId, $fieldId, $value]
        );
    }
}
