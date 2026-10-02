<?php

declare(strict_types=1);

namespace Modules\Catalog\Repositories;

use Illuminate\Database\Connection;

/**
 * Reads catalog names needed to build media storage paths.
 */
final class MediaStorageRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * Loads the series and parent category names.
     *
     * @return array<string, mixed>|null
     */
    public function findSeriesContext(int $seriesId): ?array
    {
        $row = $this->connection->selectOne(
            "SELECT s.id, s.name AS series_name, c.name AS category_name
             FROM category s
             LEFT JOIN category c ON s.parent_id = c.id
             WHERE s.id = ? AND s.type = 'series'
             LIMIT 1",
            [$seriesId]
        );

        return $row === null ? null : (array) $row;
    }
}
