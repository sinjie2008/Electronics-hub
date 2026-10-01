<?php

namespace Modules\IAM\Policies;

use App\Models\User;
use Modules\IAM\Models\Role;

class RolePolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->hasPermission($actor, 'roles.view');
    }

    public function view(User $actor, Role $role): bool
    {
        return $this->hasPermission($actor, 'roles.view');
    }

    public function create(User $actor): bool
    {
        return $this->hasPermission($actor, 'roles.create');
    }

    public function update(User $actor, Role $role): bool
    {
        return $this->hasPermission($actor, 'roles.update') && $this->canManageRole($actor, $role);
    }

    public function delete(User $actor, Role $role): bool
    {
        return $this->hasPermission($actor, 'roles.delete')
            && ! $role->isProtected()
            && $this->canManageRole($actor, $role)
            && ! $role->users()->exists();
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }

    private function canManageRole(User $actor, Role $role): bool
    {
        if (! $actor->is_active) {
            return false;
        }

        if ($role->isProtected()) {
            return $actor->can('access.super-admin');
        }

        if ($actor->can('access.super-admin')) {
            return true;
        }

        if ($role->permissions()->where('name', 'access.super-admin')->exists()) {
            return false;
        }

        foreach ($role->permissions as $permission) {
            if (! $actor->can($permission->name)) {
                return false;
            }
        }

        return true;
    }

    private function hasPermission(User $actor, string $permission): bool
    {
        return $actor->is_active && $actor->can($permission);
    }
}
