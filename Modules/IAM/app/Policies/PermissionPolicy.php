<?php

namespace Modules\IAM\Policies;

use App\Models\User;
use Modules\IAM\Models\Permission;
use Modules\IAM\Models\Role;

class PermissionPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->hasPermission($actor, 'permissions.view');
    }

    public function view(User $actor, Permission $permission): bool
    {
        return $this->hasPermission($actor, 'permissions.view');
    }

    public function create(User $actor): bool
    {
        return $this->hasPermission($actor, 'permissions.manage');
    }

    public function update(User $actor, Permission $permission): bool
    {
        return $this->hasPermission($actor, 'permissions.manage') && $this->canManagePermission($actor, $permission);
    }

    public function delete(User $actor, Permission $permission): bool
    {
        return $this->hasPermission($actor, 'permissions.manage')
            && ! $this->isProtectedPermission($permission)
            && $this->canManagePermission($actor, $permission)
            && ! $permission->roles()->exists()
            && ! $permission->users()->exists();
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }

    private function canManagePermission(User $actor, Permission $permission): bool
    {
        if (! $actor->is_active || $this->isProtectedPermission($permission)) {
            return false;
        }

        if ($actor->can('access.super-admin')) {
            return true;
        }

        if (! $actor->can($permission->name)) {
            return false;
        }

        foreach (Role::query()
            ->whereHas('permissions', fn ($query) => $query->whereKey($permission->getKey()))
            ->with('permissions')
            ->get() as $role) {
            if ($role->isPrivileged()) {
                return false;
            }

            foreach ($role->permissions as $rolePermission) {
                if (! $actor->can($rolePermission->name)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function isProtectedPermission(Permission $permission): bool
    {
        return $permission->is_system || $permission->name === 'access.super-admin';
    }

    private function hasPermission(User $actor, string $permission): bool
    {
        return $actor->is_active && $actor->can($permission);
    }
}
