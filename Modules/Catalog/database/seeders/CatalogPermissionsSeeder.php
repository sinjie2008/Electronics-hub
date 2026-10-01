<?php

declare(strict_types=1);

namespace Modules\Catalog\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\IAM\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class CatalogPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = ['catalog.view', 'catalog.create', 'catalog.update', 'catalog.delete'];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web'])
                ->forceFill(['is_system' => true])->save();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
