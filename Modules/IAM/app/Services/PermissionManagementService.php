<?php

namespace Modules\IAM\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\IAM\Models\Permission;
use Modules\IAM\Services\Concerns\AuthorizesIamOperations;
use Modules\IAM\Services\Concerns\LogsIamActivities;

class PermissionManagementService
{
    use AuthorizesIamOperations;
    use LogsIamActivities;

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $actor, array $input): Permission
    {
        $this->authorize($actor, 'create', Permission::class);
        $input = $this->normalizeInput($input);

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-z][a-z0-9_.-]*$/', Rule::unique('permissions', 'name')->where('guard_name', 'web')],
        ])->validate();

        $this->assertCustomPermissionName($validated['name']);

        return DB::transaction(function () use ($actor, $validated): Permission {
            $this->authorize($actor, 'create', Permission::class);

            $permission = Permission::query()->create([
                'name' => $validated['name'],
                'guard_name' => 'web',
                'is_system' => false,
            ]);

            $this->logIamActivity($actor, 'permission.created', $permission, [
                'name' => $permission->name,
            ]);

            return $permission;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(User $actor, Permission $permission, array $input): Permission
    {
        $permission->refresh();
        $this->authorize($actor, 'update', $permission);
        $input = $this->normalizeInput($input);

        $validated = Validator::make($input, [
            'name' => ['sometimes', 'required', 'string', 'max:255', 'regex:/^[a-z][a-z0-9_.-]*$/', Rule::unique('permissions', 'name')->where('guard_name', 'web')->ignore($permission->getKey())],
        ])->validate();

        $nextName = $validated['name'] ?? $permission->name;
        $this->assertCustomPermissionName($nextName);

        return DB::transaction(function () use ($actor, $permission, $nextName): Permission {
            $permission = Permission::query()->lockForUpdate()->findOrFail($permission->getKey());
            $this->authorize($actor, 'update', $permission);
            $this->assertPermissionIsCustom($permission);
            $this->assertCustomPermissionName($nextName);
            $previousName = $permission->name;

            if ($nextName !== $permission->name) {
                $permission->name = $nextName;
                $permission->save();
            }

            $this->logIamActivity($actor, 'permission.updated', $permission, [
                'changed_fields' => $previousName === $permission->name ? [] : ['name'],
                'changes' => $previousName === $permission->name
                    ? []
                    : ['name' => ['from' => $previousName, 'to' => $permission->name]],
            ]);

            return $permission;
        });
    }

    public function delete(User $actor, Permission $permission): void
    {
        DB::transaction(function () use ($actor, $permission): void {
            $permission = Permission::query()->lockForUpdate()->findOrFail($permission->getKey());
            $this->authorize($actor, 'delete', $permission);
            $this->assertPermissionIsCustom($permission);

            if ($permission->roles()->exists() || $permission->users()->exists()) {
                throw ValidationException::withMessages([
                    'permission' => 'Remove this permission from all roles and users before deleting it.',
                ]);
            }

            $this->logIamActivity($actor, 'permission.deleted', $permission, [
                'name' => $permission->name,
            ]);
            $permission->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalizeInput(array $input): array
    {
        if (is_string($input['name'] ?? null)) {
            $input['name'] = Str::lower(trim($input['name']));
        }

        return $input;
    }

    private function assertCustomPermissionName(string $name): void
    {
        if ($name === 'access.super-admin') {
            throw ValidationException::withMessages([
                'name' => 'The Super Admin access permission is reserved for the system.',
            ]);
        }
    }

    private function assertPermissionIsCustom(Permission $permission): void
    {
        if ($permission->is_system || $permission->name === 'access.super-admin') {
            throw ValidationException::withMessages([
                'permission' => 'System permissions cannot be changed or deleted.',
            ]);
        }
    }
}
