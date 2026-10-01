<?php

declare(strict_types=1);

namespace Modules\Catalog\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Repositories\SeriesFieldRepository;
use Modules\Catalog\Support\Db as CatalogDb;
use Modules\Catalog\Support\Seeder as CatalogSeeder;

final class CatalogDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $connectionName = config('catalog.connection') ?: config('database.default');
        DB::connection((string) $connectionName);

        $connection = CatalogDb::connection();

        (new CatalogSeeder($connection))->seedInitialData();
        $series = $connection->query("SELECT id FROM category WHERE type = 'series'");
        $fields = new SeriesFieldRepository($connection);
        foreach ($series->fetch_all(MYSQLI_ASSOC) as $row) {
            $fields->initializeMetadataDefaults((int) $row['id']);
        }
        $series->close();
    }
}
