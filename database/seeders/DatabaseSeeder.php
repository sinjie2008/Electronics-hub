<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Catalog\Database\Seeders\CatalogDatabaseSeeder;
use Nwidart\Modules\Facades\Module;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([RolesAndPermissionsSeeder::class, InitialAdminSeeder::class]);
        if (Module::has('Catalog') && Module::findOrFail('Catalog')->isEnabled()) {
            $this->call(CatalogDatabaseSeeder::class);
        }
    }
}
