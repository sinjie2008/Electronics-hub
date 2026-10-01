<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Modules\IAM\Filament\Resources\Permissions\Pages\CreatePermission;
use Modules\IAM\Filament\Resources\Permissions\Pages\EditPermission;
use Modules\IAM\Filament\Resources\Permissions\Pages\ListPermissions;
use Modules\IAM\Filament\Resources\Roles\Pages\CreateRole;
use Modules\IAM\Filament\Resources\Roles\Pages\EditRole;
use Modules\IAM\Filament\Resources\Roles\Pages\ListRoles;
use Modules\IAM\Filament\Resources\Users\Pages\CreateUser;
use Modules\IAM\Filament\Resources\Users\Pages\EditUser;
use Modules\IAM\Models\Permission;
use Modules\IAM\Models\Role;

beforeEach(function () {
    $this->withoutVite();
});

it('creates and edits a user through resource pages with verification and remember token invalidation', function () {
    $this->seed();

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(Role::SUPER_ADMIN);
    $userRole = Role::query()
        ->where('name', Role::USER)
        ->where('guard_name', 'web')
        ->firstOrFail();
    $newPassword = 'Managed-Account-Password-2937!';

    Livewire::actingAs($superAdmin, 'web')
        ->test(CreateUser::class)
        ->fillForm([
            'name' => 'Managed account',
            'email' => 'managed-account@example.test',
            'password' => 'Initial-Account-Password-1826!',
            'password_confirmation' => 'Initial-Account-Password-1826!',
            'is_active' => true,
            'role_ids' => [],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $managedUser = User::query()->where('email', 'managed-account@example.test')->firstOrFail();
    $this->assertSame([], $managedUser->roles()->pluck('roles.id')->all());

    $managedUser->forceFill([
        'email_verified_at' => now(),
        'remember_token' => 'previous-test-remember-token',
    ])->save();

    Livewire::actingAs($superAdmin, 'web')
        ->test(EditUser::class, ['record' => $managedUser->getKey()])
        ->fillForm([
            'name' => 'Updated managed account',
            'email' => 'updated-managed-account@example.test',
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
            'is_active' => false,
            'role_ids' => [$userRole->getKey()],
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $managedUser->refresh();

    expect($managedUser->email)->toBe('updated-managed-account@example.test')
        ->and($managedUser->is_active)->toBeFalse()
        ->and($managedUser->email_verified_at)->toBeNull()
        ->and($managedUser->hasRole(Role::USER, 'web'))->toBeTrue()
        ->and($managedUser->getRememberToken())->not->toBe('previous-test-remember-token')
        ->and(Hash::check($newPassword, $managedUser->password))->toBeTrue();

    Livewire::actingAs($superAdmin, 'web')
        ->test(EditUser::class, ['record' => $managedUser->getKey()])
        ->fillForm([
            'is_active' => false,
            'role_ids' => [],
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect($managedUser->fresh()->roles)->toBeEmpty();
});

it('manages custom permissions and roles and safely deletes them through table actions', function () {
    $this->seed();

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(Role::SUPER_ADMIN);

    Livewire::actingAs($superAdmin, 'web')
        ->test(CreatePermission::class)
        ->fillForm(['name' => 'reports.view'])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $permission = Permission::query()
        ->where('name', 'reports.view')
        ->where('guard_name', 'web')
        ->firstOrFail();

    Livewire::actingAs($superAdmin, 'web')
        ->test(CreateRole::class)
        ->fillForm([
            'name' => 'Report readers',
            'permission_ids' => [$permission->getKey()],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $role = Role::query()
        ->where('name', 'Report readers')
        ->where('guard_name', 'web')
        ->firstOrFail();

    expect($role->permissions->modelKeys())->toBe([$permission->getKey()]);

    Livewire::actingAs($superAdmin, 'web')
        ->test(EditRole::class, ['record' => $role->getKey()])
        ->fillForm([
            'name' => 'Report viewers',
            'permission_ids' => [],
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $role->refresh();

    expect($role->name)->toBe('Report viewers')
        ->and($role->permissions)->toBeEmpty();

    Livewire::actingAs($superAdmin, 'web')
        ->test(EditPermission::class, ['record' => $permission->getKey()])
        ->fillForm(['name' => 'reports.read'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $permission->refresh();
    expect($permission->name)->toBe('reports.read');

    Livewire::actingAs($superAdmin, 'web')
        ->test(ListRoles::class)
        ->callTableAction('delete', $role);

    $this->assertDatabaseMissing('roles', ['id' => $role->getKey()]);

    Livewire::actingAs($superAdmin, 'web')
        ->test(ListPermissions::class)
        ->callTableAction('delete', $permission);

    $this->assertDatabaseMissing('permissions', ['id' => $permission->getKey()]);
});
