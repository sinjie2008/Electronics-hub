<?php

namespace Modules\IAM\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Modules\IAM\Models\Role;
use Modules\IAM\Services\Concerns\AuthorizesIamOperations;
use Modules\IAM\Services\Concerns\LogsIamActivities;

class UserManagementService
{
    use AuthorizesIamOperations;
    use LogsIamActivities;

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $actor, array $input): User
    {
        $this->authorize($actor, 'create', User::class);
        $input = $this->normalizeUserInput($input);

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            'password_confirmation' => ['required', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['integer', 'distinct', 'exists:roles,id'],
        ])->validate();

        return DB::transaction(function () use ($actor, $validated): User {
            $this->authorize($actor, 'create', User::class);
            $roles = $this->resolveRoles($actor, $validated['role_ids'] ?? []);

            $user = new User;
            $user->fill([
                'name' => trim($validated['name']),
                'email' => Str::lower(trim($validated['email'])),
                'password' => $validated['password'],
            ]);
            $user->is_active = $validated['is_active'] ?? true;
            $user->save();

            if ($roles->isNotEmpty()) {
                $user->syncRoles($roles);
            }

            $user->load('roles');
            $this->logIamActivity($actor, 'user.created', $user, [
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'role_ids' => $user->roles->modelKeys(),
            ]);

            return $user->fresh(['roles']);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(User $actor, User $user, array $input): User
    {
        $this->authorize($actor, 'update', $user);
        $input = $this->normalizeUserInput($input);

        if (blank($input['password'] ?? null)) {
            unset($input['password'], $input['password_confirmation']);
        }

        $validated = Validator::make($input, [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->getKey())],
            'password' => ['sometimes', 'required', 'string', Password::defaults(), 'confirmed'],
            'password_confirmation' => ['sometimes', 'required', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['integer', 'distinct', 'exists:roles,id'],
        ])->validate();

        return DB::transaction(function () use ($actor, $user, $validated): User {
            $this->lockSuperAdminRoleForAccountMutation();
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $this->authorize($actor, 'update', $user);

            $before = [
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'role_ids' => $user->roles()->pluck('roles.id')->all(),
            ];
            sort($before['role_ids']);

            $hasRoleChanges = array_key_exists('role_ids', $validated);
            $roles = $hasRoleChanges
                ? $this->resolveRoles($actor, $validated['role_ids'])
                : $user->roles()->get();
            $nextIsActive = $validated['is_active'] ?? $user->is_active;

            if ($actor->is($user) && ! $this->isSuperAdmin($actor) && $hasRoleChanges) {
                $currentRoleIds = $user->roles()->pluck('roles.id')->map(fn ($id): int => (int) $id)->sort()->values();
                $nextRoleIds = $roles->modelKeys();
                sort($nextRoleIds);

                if ($currentRoleIds->all() !== $nextRoleIds) {
                    throw new AuthorizationException('Users cannot change their own roles.');
                }
            }

            if ($actor->is($user) && $user->is_active && ! $nextIsActive) {
                throw ValidationException::withMessages([
                    'is_active' => 'You cannot deactivate your own account.',
                ]);
            }

            $lastSuperAdminFailureField = ! $nextIsActive
                ? 'is_active'
                : ($hasRoleChanges ? 'role_ids' : 'is_active');

            $this->assertAnActiveSuperAdminRemains($user, (bool) $nextIsActive, $roles, $lastSuperAdminFailureField);

            $user->fill(array_filter([
                'name' => $validated['name'] ?? null,
                'email' => isset($validated['email']) ? Str::lower(trim($validated['email'])) : null,
                'password' => $validated['password'] ?? null,
            ], static fn (mixed $value): bool => $value !== null));

            if (array_key_exists('is_active', $validated)) {
                $user->is_active = (bool) $validated['is_active'];
            }

            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }

            if ($user->isDirty('password')) {
                $user->setRememberToken(Str::random(60));
            }

            $user->save();

            if ($hasRoleChanges) {
                $user->syncRoles($roles);
            }

            $user->load('roles');
            $after = [
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'role_ids' => $user->roles->modelKeys(),
            ];
            sort($after['role_ids']);
            $changes = [];

            foreach (['name', 'email', 'is_active'] as $field) {
                if ($before[$field] !== $after[$field]) {
                    $changes[$field] = ['from' => $before[$field], 'to' => $after[$field]];
                }
            }

            $this->logIamActivity($actor, 'user.updated', $user, [
                'changed_fields' => array_keys($changes),
                'changes' => $changes,
            ]);

            if ($before['role_ids'] !== $after['role_ids']) {
                $this->logIamActivity($actor, 'user.roles-changed', $user, [
                    'role_ids' => ['from' => $before['role_ids'], 'to' => $after['role_ids']],
                ]);
            }

            return $user->fresh(['roles']);
        }, attempts: 3);
    }

    public function delete(User $actor, User $user): void
    {
        DB::transaction(function () use ($actor, $user): void {
            $this->lockSuperAdminRoleForAccountMutation();
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $this->authorize($actor, 'delete', $user);

            if ($actor->is($user)) {
                throw new AuthorizationException('You cannot delete your own account.');
            }

            $this->assertAnActiveSuperAdminRemains($user, false, new Collection, 'user');
            $this->logIamActivity($actor, 'user.deleted', $user, [
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'role_ids' => $user->roles()->pluck('roles.id')->all(),
            ]);
            $user->delete();
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalizeUserInput(array $input): array
    {
        if (is_string($input['name'] ?? null)) {
            $input['name'] = trim($input['name']);
        }

        if (is_string($input['email'] ?? null)) {
            $input['email'] = Str::lower(trim($input['email']));
        }

        return $input;
    }

    /**
     * @param  array<int, int|string>  $roleIds
     * @return Collection<int, Role>
     */
    private function resolveRoles(User $actor, array $roleIds): Collection
    {
        $roleIds = array_values(array_unique($roleIds));
        $roles = Role::query()
            ->where('guard_name', 'web')
            ->whereKey($roleIds)
            ->with('permissions')
            ->lockForUpdate()
            ->get();

        if ($roles->count() !== count($roleIds)) {
            throw ValidationException::withMessages([
                'role_ids' => 'One or more selected roles are unavailable.',
            ]);
        }

        if ($this->isSuperAdmin($actor)) {
            return $roles;
        }

        foreach ($roles as $role) {
            if ($role->isPrivileged()
                || $role->permissions->contains('name', 'access.super-admin')) {
                throw new AuthorizationException('You cannot assign a protected role.');
            }

            foreach ($role->permissions as $permission) {
                if (! $actor->can($permission->name)) {
                    throw new AuthorizationException('You cannot grant a permission you do not hold.');
                }
            }
        }

        return $roles;
    }

    /**
     * @param  Collection<int, Role>  $nextRoles
     */
    private function assertAnActiveSuperAdminRemains(User $user, bool $nextIsActive, Collection $nextRoles, string $failureField = 'is_active'): void
    {
        $isCurrentlyActiveSuperAdmin = $user->is_active && $user->hasRole(Role::SUPER_ADMIN, 'web');
        $willRemainActiveSuperAdmin = $nextIsActive && $nextRoles->contains('name', Role::SUPER_ADMIN);

        if (! $isCurrentlyActiveSuperAdmin || $willRemainActiveSuperAdmin) {
            return;
        }

        $activeSuperAdmins = User::query()
            ->where('is_active', true)
            ->whereHas('roles', static fn ($query) => $query
                ->where('roles.guard_name', 'web')
                ->where('roles.name', Role::SUPER_ADMIN))
            ->orderBy('users.id')
            ->lockForUpdate()
            ->get();

        if ($activeSuperAdmins->count() <= 1) {
            throw ValidationException::withMessages([
                $failureField => 'At least one active Super Admin must remain.',
            ]);
        }
    }

    private function lockSuperAdminRoleForAccountMutation(): void
    {
        Role::query()
            ->where('guard_name', 'web')
            ->where('name', Role::SUPER_ADMIN)
            ->lockForUpdate()
            ->first();
    }
}
