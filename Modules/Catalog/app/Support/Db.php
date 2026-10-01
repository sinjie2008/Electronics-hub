<?php

declare(strict_types=1);

namespace Modules\Catalog\Support;

use mysqli;
use RuntimeException;

/** The existing SQL workflows own their MySQLi transactions on one scoped connection. */
final class Db
{
    public static function connection(): mysqli
    {
        return app('catalog.connection');
    }

    public static function fromHost(): mysqli
    {
        $config = Config::get('db');
        if (! in_array($config['driver'] ?? '', ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Catalog requires a host MySQL/MariaDB connection.');
        }
        if (($config['prefix'] ?? '') !== '') {
            throw new RuntimeException('Catalog requires its existing unprefixed table names.');
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $connection = new mysqli(
            (string) $config['host'], (string) $config['username'], (string) $config['password'],
            (string) $config['database'], (int) ($config['port'] ?? 3306), $config['unix_socket'] ?? null
        );
        $connection->set_charset($config['charset'] ?? 'utf8mb4');

        return $connection;
    }
}
