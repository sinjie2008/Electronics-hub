<?php

declare(strict_types=1);

namespace Modules\Catalog\Support;

use Illuminate\Database\ConfigurationUrlParser;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Http\CatalogApiException;

/** A separate account and a read-only MySQL session protect every API query. */
final class PublicApiConnection
{
    public function connection(): Connection
    {
        $settings = (array) config('database.connections.catalog_public_api', []);
        $catalog = (array) config('database.connections.'.config('catalog.connection'), []);
        $catalog = (new ConfigurationUrlParser)->parseConfiguration($catalog);

        if (($settings['driver'] ?? null) !== 'mysql'
            || empty($settings['username'])
            || ! is_string($settings['password'] ?? null) || $settings['password'] === ''
            || $settings['username'] === ($catalog['username'] ?? '')
            || ($settings['database'] ?? '') !== ($catalog['database'] ?? '')) {
            throw new CatalogApiException('API_UNAVAILABLE', 'The public product API is unavailable.', 503);
        }

        $connection = DB::connection('catalog_public_api');
        $grants = $connection->select('SHOW GRANTS FOR CURRENT_USER');
        foreach ($grants as $grant) {
            $statement = (string) array_values((array) $grant)[0];
            if (! preg_match('/^GRANT (?:USAGE|SELECT) ON /i', $statement)
                || stripos($statement, 'WITH GRANT OPTION') !== false) {
                throw new CatalogApiException('API_UNAVAILABLE', 'The public product API is unavailable.', 503);
            }
        }

        $connection->getPdo()->exec('SET SESSION TRANSACTION READ ONLY');

        return $connection;
    }
}
