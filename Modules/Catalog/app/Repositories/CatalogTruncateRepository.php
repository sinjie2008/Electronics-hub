<?php

declare(strict_types=1);

namespace Modules\Catalog\Repositories;

use Illuminate\Database\Connection;
use Modules\Catalog\Support\Config;

/**
 * Performs catalog row counts, truncation statements, and advisory lock queries.
 */
final class CatalogTruncateRepository
{
    public function __construct(private Connection $connection) {}

    /**
     * Counts category or series nodes by their type.
     */
    public function countCategoriesByType(string $type): int
    {
        return (int) $this->connection->scalar('SELECT COUNT(1) AS total FROM category WHERE type = ?', [$type]);
    }

    /**
     * Counts product rows.
     */
    public function countProducts(): int
    {
        return $this->countTableRows('product');
    }

    /**
     * Counts custom field definitions.
     */
    public function countFieldDefinitions(): int
    {
        return $this->countTableRows('series_custom_field');
    }

    /**
     * Counts product custom values.
     */
    public function countProductValues(): int
    {
        return $this->countTableRows('product_custom_field_value');
    }

    /**
     * Counts series custom values.
     */
    public function countSeriesValues(): int
    {
        return $this->countTableRows('series_custom_field_value');
    }

    /**
     * Disables or enables foreign key checks on the current connection.
     */
    public function setForeignKeyChecks(bool $enabled): void
    {
        $value = $enabled ? 1 : 0;
        $this->connection->statement(sprintf('SET FOREIGN_KEY_CHECKS = %d', $value));
    }

    /**
     * Truncates the catalog tables in their established order.
     */
    public function truncateCatalogTables(): void
    {
        foreach ([
            'product_custom_field_value',
            'series_custom_field_value',
            'product',
            'series_custom_field',
            'category',
            'seed_migration',
        ] as $table) {
            $this->connection->statement(sprintf('TRUNCATE TABLE %s', $table));
        }
    }

    /**
     * Attempts to acquire the catalog truncate lock without waiting.
     */
    public function acquireTruncateLock(): bool
    {
        $lockKey = Config::get('app')['truncate']['lock_key'];
        $row = $this->connection->selectOne('SELECT GET_LOCK(?, 0) AS lock_obtained', [$lockKey]);

        return (int) ($row->lock_obtained ?? 0) === 1;
    }

    /**
     * Releases the catalog truncate lock.
     */
    public function releaseTruncateLock(): void
    {
        $lockKey = Config::get('app')['truncate']['lock_key'];
        $this->connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockKey]);
    }

    /**
     * Counts rows in a table used by the fixed catalog audit count methods.
     */
    private function countTableRows(string $table): int
    {
        return (int) $this->connection->scalar(sprintf('SELECT COUNT(1) AS total FROM %s', $table));
    }
}
