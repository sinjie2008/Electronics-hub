<?php

namespace Modules\IAM\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\IAM\Models\Permission;
use Modules\IAM\Models\Role;
use Modules\IAM\Services\Concerns\AuthorizesIamOperations;
use Modules\IAM\Services\Concerns\LogsIamActivities;

class RoleManagementService
{
    use AuthorizesIamOperations;
    use LogsIamActivities;

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $actor, array $input): Role
    {
        $this->authorize($actor, 'create', Role::class);
        $input = $this->normalizeInput($input);

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->where('guard_name', 'web')],
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ])->validate();

        $this->assertCustomRoleName($validated['name']);

        return DB::transaction(function () use ($actor, $validated): Role {
            $this->authorize($actor, 'create', Role::class);
            $permissions = $this->resolvePermissions($validated['permission_ids'] ?? []);
            $this->assertSuperAdminPermissionIsNotGranted($permissions);
            $this->assertMayGrantPermissions($actor, $permissions);

            $role = Role::query()->create([
                'name' => $validated['name'],
                'guard_name' => 'web',
                'is_system' => false,
            ]);

            if ($permissions->isNotEmpty()) {
                $role->syncPermissions($permissions);
            }

            $role->load('permissions');
            $this->logIamActivity($actor, 'role.created', $role, [
                'name' => $role->name,
                'permission_ids' => $role->permissions->modelKeys(),
            ]);

            return $role->fresh(['permissions']);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(User $actor, Role $role, array $input): Role
    {
        $role->refresh()->load('permissions');
        $this->authorize($actor, 'update', $role);
        $input = $this->normalizeInput($input);

        $validated = Validator::make($input, [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($role->getKey())],
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ])->validate();

        $nextName = $validated['name'] ?? $role->name;

        if ($nextName !== $role->name && $role->isProtected()) {
            throw ValidationException::withMessages([
                'name' => 'Protected system roles cannot be renamed.',
            ]);
        }

        if (! $role->isProtected()) {
            $this->assertCustomRoleName($nextName);
        }

        return DB::transaction(function () use ($actor, $role, $validated, $nextName): Role {
            $role = Role::query()->lockForUpdate()->findOrFail($role->getKey());
            $this->authorize($actor, 'update', $role);

            $beforeName = $role->name;
            $beforePermissionIds = $role->permissions()->pluck('permissions.id')->all();
            sort($beforePermissionIds);

            $permissions = null;

            if (array_key_exists('permission_ids', $validated)) {
                $permissions = $this->resolvePermissions($validated['permission_ids']);
                $this->assertSuperAdminPermissionIsNotGranted($permissions);
                $this->assertMayGrantPermissions($actor, $permissions);
            }

            if ($nextName !== $role->name && $role->isProtected()) {
                throw ValidationException::withMessages([
                    'name' => 'Protected system roles cannot be renamed.',
                ]);
            }

            if ($nextName !== $role->name) {
                $this->assertCustomRoleName($nextName);
                $role->name = $nextName;
                $role->save();
            }

            if ($permissions !== null) {
                $role->syncPermissions($permissions);
            }

            $role->load('permissions');
            $afterPermissionIds = $role->permissions->modelKeys();
            sort($afterPermissionIds);
            $changes = [];

            if ($beforeName !== $role->name) {
                $changes['name'] = ['from' => $beforeName, 'to' => $role->name];
            }

            if ($beforePermissionIds !== $afterPermissionIds) {
                $changes['permission_ids'] = ['from' => $beforePermissionIds, 'to' => $afterPermissionIds];
            }

            $this->logIamActivity($actor, 'role.updated', $role, [
                'changed_fields' => array_keys($changes),
                'changes' => $changes,
            ]);

            return $role->fresh(['permissions']);
        });
    }

    public function delete(User $actor, Role $role): void
    {
        DB::transaction(function () use ($actor, $role): void {
            $role = Role::query()->lockForUpdate()->findOrFail($role->getKey());
            $this->authorize($actor, 'delete', $role);

            if ($role->isProtected()) {
                throw ValidationException::withMessages([
                    'role' => 'Protected system roles cannot be deleted.',
                ]);
            }

            if ($role->users()->exists()) {
                throw ValidationException::withMessages([
                    'role' => 'Remove this role from all users before deleting it.',
                ]);
            }

            $this->logIamActivity($actor, 'role.deleted', $role, [
                'name' => $role->name,
                'permission_ids' => $role->permissions()->pluck('permissions.id')->all(),
            ]);
            $role->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalizeInput(array $input): array
    {
        if (is_string($input['name'] ?? null)) {
            $input['name'] = trim($input['name']);
        }

        return $input;
    }

    private function assertCustomRoleName(string $name): void
    {
        if (Role::isReservedName($name)) {
            throw ValidationException::withMessages([
                'name' => 'This role name is reserved for the system.',
            ]);
        }
    }

    /**
     * @param  array<int, int|string>  $permissionIds
     * @return Collection<int, Permission>
     */
    private function resolvePermissions(array $permissionIds): Collection
    {
        $permissionIds = array_values(array_unique($permissionIds));
        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->whereKey($permissionIds)
            ->lockForUpdate()
            ->get();

        if ($permissions->count() !== count($permissionIds)) {
            throw ValidationException::withMessages([
                'permission_ids' => 'One or more selected permissions are unavailable.',
            ]);
        }

        return $permissions;
    }

    /**
     * @param  Collection<int, Permission>  $permissions
     */
    private function assertMayGrantPermissions(User $actor, Collection $permissions): void
    {
        if ($this->isSuperAdmin($actor)) {
            return;
        }

        foreach ($permissions as $permission) {
            if ($permission->name === 'access.super-admin' || ! $actor->can($permission->name)) {
                throw new AuthorizationException('You cannot grant a permission you do not hold.');
            }
        }
    }

    /**
     * @param  Collection<int, Permission>  $permissions
     */
    private function assertSuperAdminPermissionIsNotGranted(Collection $permissions): void
    {
        if ($permissions->contains('name', 'access.super-admin')) {
            throw ValidationException::withMessages([
                'permission_ids' => 'The Super Admin bypass is controlled by the protected Super Admin role.',
            ]);
        }
    }
}
