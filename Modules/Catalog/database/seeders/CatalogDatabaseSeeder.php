<?php

declare(strict_types=1);

namespace Modules\Catalog\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\IAM\Models\Permission;
use Modules\IAM\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CatalogDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = [];
        foreach (['view', 'manage', 'csv', 'templates', 'truncate'] as $ability) {
            $permission = Permission::query()->firstOrCreate(['name' => 'catalog.'.$ability, 'guard_name' => 'web']);
            $permission->forceFill(['is_system' => true])->save();
            $permissions[] = $permission;
        }
        foreach ([Role::SUPER_ADMIN, 'Admin'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            $role?->givePermissionTo($permissions);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
