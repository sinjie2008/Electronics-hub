<?php

namespace Modules\IAM\Policies;

use App\Models\User;
use Modules\IAM\Models\Role;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->hasPermission($actor, 'users.view');
    }

    public function view(User $actor, User $user): bool
    {
        return $this->hasPermission($actor, 'users.view') && $this->canManage($actor, $user);
    }

    public function create(User $actor): bool
    {
        return $this->hasPermission($actor, 'users.create');
    }

    public function update(User $actor, User $user): bool
    {
        return $this->hasPermission($actor, 'users.update') && $this->canManage($actor, $user);
    }

    public function delete(User $actor, User $user): bool
    {
        return $this->hasPermission($actor, 'users.delete')
            && ! $actor->is($user)
            && $this->canManage($actor, $user);
    }

    public function deleteAny(User $actor): bool
    {
        // Bulk delete is intentionally not exposed. The service provides the
        // per-user safety checks needed for self and final-admin protection.
        return false;
    }

    private function canManage(User $actor, User $target): bool
    {
        if (! $target->exists) {
            return true;
        }

        if ($actor->can('access.super-admin')) {
            return true;
        }

        if ($actor->is($target)) {
            return true;
        }

        if ($target->getAllPermissions()->contains('name', 'access.super-admin')) {
            return false;
        }

        $targetHasPrivilegedRole = Role::query()
            ->whereHas('users', static fn ($query) => $query->whereKey($target->getKey()))
            ->get()
            ->contains(static fn (Role $role): bool => $role->isPrivileged());

        if ($targetHasPrivilegedRole) {
            return false;
        }

        foreach ($target->getAllPermissions() as $permission) {
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
