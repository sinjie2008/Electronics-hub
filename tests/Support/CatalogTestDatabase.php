<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Isolates Catalog's MySQLi data from the host test database. */
final class CatalogTestDatabase
{
    private const MIGRATION = '2026_10_01_000000_create_catalog_schema';

    /** @var list<string> */
    private const TABLES = [
        'category',
        'product',
        'series_custom_field',
        'product_custom_field_value',
        'series_custom_field_value',
        'latex_template',
        'seed_migration',
        'typst_templates',
        'typst_variables',
        'typst_series_preferences',
        'latex_templates',
        'latex_variables',
        'global_variables',
    ];

    /** Create the Catalog schema, clear only Catalog-owned rows, and isolate its files. */
    public static function prepare(): string
    {
        self::assertDisposableConnection();
        self::migrate();
        self::clearRows();

        $storageRoot = storage_path('framework/testing/catalog-'.bin2hex(random_bytes(8)));
        config([
            'catalog.storage_root' => $storageRoot,
            'catalog.settings.storage' => [],
        ]);

        return $storageRoot;
    }

    /** Clear only the allow-listed Catalog tables and the current test's files. */
    public static function cleanup(?string $storageRoot = null): void
    {
        try {
            self::assertDisposableConnection();
            self::clearRows();
        } finally {
            if ($storageRoot !== null) {
                self::deleteTestStorage($storageRoot);
            }
        }
    }

    /** Run Catalog's migration only against its named disposable database. */
    public static function migrate(): void
    {
        self::assertDisposableConnection();

        $exitCode = Artisan::call('module:migrate', [
            'module' => 'Catalog',
            '--database' => 'catalog',
            '--force' => true,
        ]);

        if ($exitCode !== 0) {
            throw new RuntimeException('Catalog module migration failed: '.Artisan::output());
        }
    }

    /** Drop only Catalog-owned tables for an isolated legacy-schema migration test. */
    public static function dropOwnedTables(): void
    {
        $connection = self::connect();
        try {
            $connection->query('SET FOREIGN_KEY_CHECKS = 0');
            foreach (array_reverse(self::TABLES) as $table) {
                $connection->query('DROP TABLE IF EXISTS `'.$table.'`');
            }
        } finally {
            $connection->query('SET FOREIGN_KEY_CHECKS = 1');
            $connection->close();
        }
    }

    /** Remove this module's Laravel migration marker so module:migrate reapplies its migration. */
    public static function forgetMigrationRecord(): void
    {
        self::assertDisposableConnection();
        $connection = self::connect();
        try {
            $statement = $connection->prepare('DELETE FROM `migrations` WHERE `migration` = ?');
            $migration = self::MIGRATION;
            $statement->bind_param('s', $migration);
            $statement->execute();
            $statement->close();
        } finally {
            $connection->close();
        }
    }

    /** @return list<array<string, mixed>> */
    public static function freshBootRoutes(bool $enabled): array
    {
        $statusPath = storage_path('framework/testing/catalog-modules-'.bin2hex(random_bytes(8)).'.json');
        $routesCache = storage_path('framework/testing/catalog-routes-'.bin2hex(random_bytes(8)).'.php');
        $configCache = storage_path('framework/testing/catalog-config-'.bin2hex(random_bytes(8)).'.php');
        File::ensureDirectoryExists(dirname($statusPath));
        File::put($statusPath, json_encode([
            'Core' => true,
            'IAM' => true,
            'System' => true,
            'Catalog' => $enabled,
        ], JSON_THROW_ON_ERROR));

        try {
            $process = new Process([
                PHP_BINARY,
                'artisan',
                'route:list',
                '--path=catalog',
                '--json',
                '--no-ansi',
            ], base_path(), [
                'APP_ENV' => 'testing',
                'APP_CONFIG_CACHE' => $configCache,
                'APP_ROUTES_CACHE' => $routesCache,
                'MODULE_STATUSES_PATH' => $statusPath,
            ]);
            $process->setTimeout(45);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException('Fresh Catalog bootstrap failed: '.$process->getErrorOutput());
            }

            $routes = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($routes)) {
                throw new RuntimeException('Fresh route:list output was not a JSON array.');
            }

            return $routes;
        } finally {
            File::delete($statusPath, $statusPath.'.lock', $routesCache, $configCache);
        }
    }

    /** Assert that the named Catalog connection cannot point at a live host database. */
    private static function assertDisposableConnection(): void
    {
        $connectionName = (string) config('catalog.connection');
        $connection = (array) config('database.connections.catalog', []);
        $database = (string) ($connection['database'] ?? '');
        $driver = (string) ($connection['driver'] ?? '');

        if ($connectionName !== 'catalog') {
            throw new RuntimeException('Catalog tests require catalog.connection=catalog.');
        }
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Catalog tests require the independent MySQL/MariaDB catalog connection.');
        }
        if (! preg_match('/_(?:test|testing)$/i', $database)) {
            throw new RuntimeException('Refusing Catalog test setup: CATALOG_DB_DATABASE must end in _test or _testing.');
        }
        if (($connection['prefix'] ?? '') !== '') {
            throw new RuntimeException('Catalog tests require unprefixed Catalog table names.');
        }

        $hostConnection = (array) config('database.connections.'.config('database.default'), []);
        if (in_array($hostConnection['driver'] ?? '', ['mysql', 'mariadb'], true)
            && strtolower($database) === strtolower((string) ($hostConnection['database'] ?? ''))
            && (string) ($connection['host'] ?? '') === (string) ($hostConnection['host'] ?? '')
            && (string) ($connection['port'] ?? '') === (string) ($hostConnection['port'] ?? '')) {
            throw new RuntimeException('Refusing Catalog tests: the Catalog and host connections point at the same database.');
        }
    }

    private static function clearRows(): void
    {
        $connection = self::connect();
        try {
            $existing = [];
            $result = $connection->query('SHOW TABLES');
            while ($row = $result->fetch_row()) {
                $existing[] = (string) $row[0];
            }
            $result->close();

            $connection->query('SET FOREIGN_KEY_CHECKS = 0');
            foreach (array_reverse(self::TABLES) as $table) {
                if (in_array($table, $existing, true)) {
                    $connection->query('DELETE FROM `'.$table.'`');
                }
            }
            $connection->query('SET FOREIGN_KEY_CHECKS = 1');
        } finally {
            $connection->close();
        }
    }

    private static function connect(): \mysqli
    {
        self::assertDisposableConnection();
        $connectionConfig = (array) config('database.connections.catalog');
        $connection = new \mysqli(
            (string) $connectionConfig['host'],
            (string) $connectionConfig['username'],
            (string) $connectionConfig['password'],
            (string) $connectionConfig['database'],
            (int) ($connectionConfig['port'] ?? 3306),
            $connectionConfig['unix_socket'] ?? null,
        );
        $connection->set_charset((string) ($connectionConfig['charset'] ?? 'utf8mb4'));

        return $connection;
    }

    private static function deleteTestStorage(string $storageRoot): void
    {
        $expectedPrefix = storage_path('framework/testing/catalog-');
        if (! str_starts_with(strtolower($storageRoot), strtolower($expectedPrefix))) {
            throw new RuntimeException('Refusing to remove a Catalog test storage path outside storage/framework/testing.');
        }

        File::deleteDirectory($storageRoot);
    }
}
