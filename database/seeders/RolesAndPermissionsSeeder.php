<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\IAM\Models\Permission;
use Modules\IAM\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'access.admin', 'system-info.view',
        'users.view', 'users.create', 'users.update', 'users.delete',
        'roles.view', 'roles.create', 'roles.update', 'roles.delete',
        'permissions.view', 'permissions.manage',
        'modules.view', 'modules.enable', 'modules.disable',
        'backups.view', 'backups.create', 'backups.download',
        'settings.view', 'settings.update', 'activity-log.view',
        'oauth-clients.view', 'oauth-clients.create', 'oauth-clients.revoke',
        'search.use',
    ];

    public const ADMIN_PERMISSIONS = [
        'access.admin', 'system-info.view',
        'users.view', 'users.create', 'users.update', 'users.delete',
        'roles.view', 'roles.create', 'roles.update', 'roles.delete',
        'permissions.view', 'modules.view', 'backups.view', 'backups.create',
        'settings.view', 'settings.update', 'activity-log.view',
        'oauth-clients.view', 'oauth-clients.create', 'oauth-clients.revoke',
        'search.use',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::PERMISSIONS as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web'])
                ->forceFill(['is_system' => true])->save();
        }

        foreach (['Super Admin' => self::PERMISSIONS, 'Admin' => self::ADMIN_PERMISSIONS, 'User' => []] as $name => $permissions) {
            $role = Role::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $role->forceFill(['is_system' => true])->save();
            if ($role->wasRecentlyCreated) {
                $role->syncPermissions($permissions);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
