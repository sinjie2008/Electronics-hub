<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Catalog\Database\Seeders\CatalogPermissionsSeeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app('modules')->isEnabled('Catalog')) {
            $this->call(CatalogPermissionsSeeder::class);
        }
        $this->call([RolesAndPermissionsSeeder::class, InitialAdminSeeder::class]);
    }
}
