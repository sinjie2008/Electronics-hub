<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\IAM\Models\Permission;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_only_active_administrative_users_can_access_the_filament_panel(): void
    {
        $panel = Panel::make()->id('admin');
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $ordinaryUser = User::factory()->create();
        $inactiveAdmin = User::factory()->create(['is_active' => false]);
        $inactiveAdmin->assignRole('Admin');

        $this->assertTrue($admin->canAccessPanel($panel));
        $this->assertFalse($ordinaryUser->canAccessPanel($panel));
        $this->assertFalse($inactiveAdmin->canAccessPanel($panel));
    }

    public function test_user_search_documents_contain_only_safe_identity_fields(): void
    {
        $user = User::factory()->create([
            'email' => 'private@example.test',
            'password' => 'Confidential-Password-9031',
        ]);

        $this->assertSame([
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
        ], $user->toSearchableArray());
        $this->assertArrayNotHasKey('password', $user->toSearchableArray());
        $this->assertArrayNotHasKey('remember_token', $user->toSearchableArray());
    }

    public function test_email_verification_contract_is_backed_by_the_laravel_trait(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $this->assertFalse($user->hasVerifiedEmail());

        $user->markEmailAsVerified();

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_super_admin_gate_bypass_requires_an_active_super_admin_role(): void
    {
        $permissionOnlyAdmin = User::factory()->create();
        $permissionOnlyAdmin->assignRole('Admin');
        $superAdminPermission = Permission::query()->create([
            'name' => 'access.super-admin',
            'guard_name' => 'web',
            'is_system' => true,
        ]);
        $permissionOnlyAdmin->givePermissionTo($superAdminPermission);

        $activeSuperAdmin = User::factory()->create();
        $activeSuperAdmin->assignRole('Super Admin');
        $inactiveSuperAdmin = User::factory()->create(['is_active' => false]);
        $inactiveSuperAdmin->assignRole('Super Admin');

        $this->assertFalse($permissionOnlyAdmin->can('access.super-admin'));
        $this->assertTrue($activeSuperAdmin->can('permissions.manage'));
        $this->assertFalse($inactiveSuperAdmin->can('permissions.manage'));
    }

    public function test_inactive_users_cannot_use_direct_permissions(): void
    {
        $inactiveUser = User::factory()->create(['is_active' => false]);
        $inactiveUser->givePermissionTo('users.delete');

        $this->assertFalse($inactiveUser->can('users.delete'));
    }

    public function test_a_generic_permission_does_not_bypass_model_policies(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo(Permission::query()->create([
            'name' => 'update',
            'guard_name' => 'web',
            'is_system' => false,
        ]));
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('Super Admin');

        $this->assertFalse($admin->can('update', $superAdmin));
    }
}
