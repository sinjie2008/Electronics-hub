<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\IAM\Models\Permission;
use Modules\IAM\Models\Role;
use Modules\IAM\Services\PermissionManagementService;
use Modules\IAM\Services\RoleManagementService;
use Modules\IAM\Services\UserManagementService;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

final class IamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_user_creation_hashes_password_and_assigns_only_authorized_roles(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $userRole = Role::query()->where('name', 'User')->where('guard_name', 'web')->firstOrFail();

        $user = app(UserManagementService::class)->create($admin, [
            'name' => 'New account',
            'email' => 'new-account@example.test',
            'password' => 'Correct-Horse-7482',
            'password_confirmation' => 'Correct-Horse-7482',
            'role_ids' => [$userRole->getKey()],
        ]);

        $this->assertTrue(Hash::check('Correct-Horse-7482', $user->password));
        $this->assertTrue($user->hasRole('User', 'web'));
        $this->assertTrue($user->is_active);
    }

    public function test_an_administrator_cannot_assign_the_super_admin_role(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $superAdminRole = Role::query()->where('name', 'Super Admin')->where('guard_name', 'web')->firstOrFail();

        try {
            app(UserManagementService::class)->create($admin, [
                'name' => 'Escalated user',
                'email' => 'escalated@example.test',
                'password' => 'Correct-Horse-7482',
                'password_confirmation' => 'Correct-Horse-7482',
                'role_ids' => [$superAdminRole->getKey()],
            ]);

            $this->fail('An ordinary administrator assigned the Super Admin role.');
        } catch (AuthorizationException) {
            $this->assertDatabaseMissing('users', ['email' => 'escalated@example.test']);
        }
    }

    public function test_an_administrator_cannot_edit_a_more_privileged_user_or_role(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('Super Admin');
        $originalName = $superAdmin->name;

        try {
            app(UserManagementService::class)->update($admin, $superAdmin, ['name' => 'Escalated']);
            $this->fail('An ordinary administrator edited a Super Admin account.');
        } catch (AuthorizationException) {
            $this->assertSame($originalName, $superAdmin->fresh()->name);
        }

        $unheldPermission = Permission::query()->where('name', 'backups.download')->where('guard_name', 'web')->firstOrFail();
        $privilegedRole = Role::query()->create([
            'name' => 'Archive manager',
            'guard_name' => 'web',
            'is_system' => false,
        ]);
        $privilegedRole->syncPermissions([$unheldPermission]);

        try {
            app(RoleManagementService::class)->update($admin, $privilegedRole, ['name' => 'Renamed archive manager']);
            $this->fail('An ordinary administrator edited a more privileged role.');
        } catch (AuthorizationException) {
            $this->assertSame('Archive manager', $privilegedRole->fresh()->name);
        }
    }

    public function test_an_administrator_cannot_grant_a_permission_they_do_not_hold(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('Super Admin');
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $unheldPermission = Permission::query()->create([
            'name' => 'reports.export',
            'guard_name' => 'web',
            'is_system' => false,
        ]);

        try {
            app(RoleManagementService::class)->create($admin, [
                'name' => 'Export manager',
                'permission_ids' => [$unheldPermission->getKey()],
            ]);

            $this->fail('An ordinary administrator granted a permission they do not hold.');
        } catch (AuthorizationException) {
            $this->assertDatabaseMissing('roles', ['name' => 'Export manager', 'guard_name' => 'web']);
        }
    }

    public function test_users_cannot_deactivate_themselves_or_remove_their_own_roles(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('Super Admin');
        $userService = app(UserManagementService::class);

        try {
            $userService->update($superAdmin, $superAdmin, ['is_active' => false]);
            $this->fail('A Super Admin deactivated their own account.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('is_active', $exception->errors());
        }

        try {
            $userService->delete($superAdmin, $superAdmin);
            $this->fail('A Super Admin deleted their own account.');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('users', ['id' => $superAdmin->getKey()]);
        }

        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        try {
            $userService->update($admin, $admin, ['role_ids' => []]);
            $this->fail('An administrator changed their own role assignment.');
        } catch (AuthorizationException) {
            $this->assertTrue($admin->fresh()->hasRole('Admin', 'web'));
        }
    }

    public function test_the_last_active_super_admin_cannot_be_removed(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('Super Admin');

        try {
            app(UserManagementService::class)->update($superAdmin, $superAdmin, ['role_ids' => []]);
            $this->fail('The last active Super Admin role was removed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('role_ids', $exception->errors());
            $this->assertTrue($superAdmin->fresh()->hasRole('Super Admin', 'web'));
        }
    }

    public function test_protected_roles_cannot_be_renamed_or_deleted(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('Super Admin');
        $role = Role::query()->where('name', 'Admin')->where('guard_name', 'web')->firstOrFail();
        $service = app(RoleManagementService::class);

        try {
            $service->update($superAdmin, $role, ['name' => 'Privileged editor']);
            $this->fail('A protected role was renamed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('name', $exception->errors());
        }

        try {
            $service->delete($superAdmin, $role);
            $this->fail('A protected role was deleted.');
        } catch (AuthorizationException|ValidationException) {
            $this->assertDatabaseHas('roles', ['id' => $role->getKey(), 'name' => 'Admin']);
        }
    }

    public function test_system_permissions_cannot_be_renamed_or_deleted(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('Super Admin');
        $permission = Permission::query()->where('name', 'users.create')->where('guard_name', 'web')->firstOrFail();
        $service = app(PermissionManagementService::class);

        try {
            $service->update($superAdmin, $permission, ['name' => 'users.invite']);
            $this->fail('A system permission was renamed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('permission', $exception->errors());
        }

        try {
            $service->delete($superAdmin, $permission);
            $this->fail('A system permission was deleted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('permission', $exception->errors());
            $this->assertDatabaseHas('permissions', ['id' => $permission->getKey(), 'name' => 'users.create']);
        }
    }

    public function test_blank_password_is_ignored_during_user_updates(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $user = User::factory()->create(['password' => 'Original-Password-8642']);
        $previousHash = $user->password;

        app(UserManagementService::class)->update($admin, $user, [
            'name' => 'Updated name',
            'password' => '',
            'password_confirmation' => '',
        ]);

        $this->assertSame($previousHash, $user->fresh()->password);
    }

    public function test_managed_email_and_password_changes_invalidate_previous_verification_and_remember_token(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $user = User::factory()->create(['remember_token' => 'previous-test-remember-token']);
        $password = 'Changed-Password-7946!';

        app(UserManagementService::class)->update($admin, $user, [
            'email' => 'new-verified-address@example.test',
            'password' => $password,
            'password_confirmation' => $password,
        ]);

        $user->refresh();
        $this->assertNull($user->email_verified_at);
        $this->assertNotSame('previous-test-remember-token', $user->getRememberToken());
        $this->assertTrue(Hash::check($password, $user->password));
    }

    public function test_iam_audit_events_record_safe_changes_without_credentials(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('Super Admin');
        $permissionService = app(PermissionManagementService::class);
        $roleService = app(RoleManagementService::class);
        $userService = app(UserManagementService::class);

        $permission = $permissionService->create($superAdmin, ['name' => 'reports.view']);
        $permission = $permissionService->update($superAdmin, $permission, ['name' => 'reports.read']);
        $role = $roleService->create($superAdmin, [
            'name' => 'Report reader',
            'permission_ids' => [$permission->getKey()],
        ]);
        $roleService->update($superAdmin, $role, [
            'name' => 'Report viewer',
            'permission_ids' => [],
        ]);
        $roleService->delete($superAdmin, $role);
        $permissionService->delete($superAdmin, $permission);

        $userRole = Role::query()->where('name', Role::USER)->where('guard_name', 'web')->firstOrFail();
        $originalPassword = 'Original-Horse-3947';
        $updatedPassword = 'Updated-Horse-4568';
        $user = $userService->create($superAdmin, [
            'name' => 'Managed account',
            'email' => 'managed-account@example.test',
            'password' => $originalPassword,
            'password_confirmation' => $originalPassword,
            'role_ids' => [$userRole->getKey()],
        ]);
        $user = $userService->update($superAdmin, $user, [
            'name' => 'Renamed account',
            'password' => $updatedPassword,
            'password_confirmation' => $updatedPassword,
            'role_ids' => [],
        ]);
        $userService->delete($superAdmin, $user);

        $activities = Activity::query()->where('causer_id', $superAdmin->getKey())->get();
        $events = $activities->pluck('event')->all();

        foreach ([
            'user.created', 'user.updated', 'user.roles-changed', 'user.deleted',
            'role.created', 'role.updated', 'role.deleted',
            'permission.created', 'permission.updated', 'permission.deleted',
        ] as $event) {
            $this->assertContains($event, $events);
        }

        foreach ($activities as $activity) {
            $properties = json_encode($activity->properties->toArray(), JSON_THROW_ON_ERROR);

            $this->assertStringNotContainsString('password', strtolower($properties));
            $this->assertStringNotContainsString('remember_token', strtolower($properties));
            $this->assertStringNotContainsString($originalPassword, $properties);
            $this->assertStringNotContainsString($updatedPassword, $properties);
        }
    }
}
