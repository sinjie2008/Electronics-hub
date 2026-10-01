<?php

use App\Models\User;
use Database\Seeders\InitialAdminSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\IAM\Models\Role;

beforeEach(function () {
    config(['enterprise.initial_admin' => ['name' => null, 'email' => null, 'password' => null]]);
});

it('creates a normalized environment administrator and leaves a matching existing account unchanged', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['enterprise.initial_admin' => [
        'name' => '  Bootstrap Administrator  ',
        'email' => '  Bootstrap.Admin@Example.Test  ',
        'password' => 'Bootstrap-Admin-Password-2936!',
    ]]);

    $this->seed(InitialAdminSeeder::class);

    $administrator = User::query()->where('email', 'bootstrap.admin@example.test')->firstOrFail();

    expect($administrator->name)->toBe('Bootstrap Administrator')
        ->and($administrator->is_active)->toBeTrue()
        ->and($administrator->hasVerifiedEmail())->toBeTrue()
        ->and($administrator->hasRole(Role::SUPER_ADMIN, 'web'))->toBeTrue()
        ->and(Hash::check('Bootstrap-Admin-Password-2936!', $administrator->password))->toBeTrue();

    config(['enterprise.initial_admin' => [
        'name' => 'Replacement Administrator',
        'email' => 'BOOTSTRAP.ADMIN@EXAMPLE.TEST',
        'password' => 'Replacement-Admin-Password-2947!',
    ]]);

    $this->seed(InitialAdminSeeder::class);
    $administrator->refresh();

    expect(User::query()->count())->toBe(1)
        ->and($administrator->name)->toBe('Bootstrap Administrator')
        ->and(Hash::check('Bootstrap-Admin-Password-2936!', $administrator->password))->toBeTrue()
        ->and(Hash::check('Replacement-Admin-Password-2947!', $administrator->password))->toBeFalse();
});

it('rejects partial environment credentials without creating an administrator', function () {
    config(['enterprise.initial_admin' => [
        'name' => 'Incomplete Administrator',
        'email' => null,
        'password' => null,
    ]]);

    expect(fn () => app(InitialAdminSeeder::class)->run())
        ->toThrow(ValidationException::class);

    expect(User::query()->count())->toBe(0);
});

it('does not overwrite an existing account with a case-insensitive matching email', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $existingPassword = 'Existing-Account-Password-8301!';
    $existingUser = User::factory()->create([
        'name' => 'Existing account',
        'email' => 'BOOTSTRAP.ADMIN@EXAMPLE.TEST',
        'password' => $existingPassword,
        'is_active' => false,
    ]);
    config(['enterprise.initial_admin' => [
        'name' => 'Replacement Administrator',
        'email' => 'bootstrap.admin@example.test',
        'password' => 'Replacement-Admin-Password-2947!',
    ]]);

    $this->seed(InitialAdminSeeder::class);
    $existingUser->refresh();

    expect(User::query()->count())->toBe(1)
        ->and($existingUser->name)->toBe('Existing account')
        ->and($existingUser->is_active)->toBeFalse()
        ->and($existingUser->hasRole(Role::SUPER_ADMIN, 'web'))->toBeFalse()
        ->and(Hash::check($existingPassword, $existingUser->password))->toBeTrue()
        ->and(Hash::check('Replacement-Admin-Password-2947!', $existingUser->password))->toBeFalse();
});

it('creates a normalized verified super administrator through the interactive CLI command', function () {
    $password = 'Command-Line-Admin-Password-3957!';

    $this->artisan('app:create-admin')
        ->expectsQuestion('Administrator name', '  Command Line Administrator  ')
        ->expectsQuestion('Administrator email', '  Command-Line.Admin@Example.Test  ')
        ->expectsQuestion('Password (12+ characters, mixed case, number and symbol)', $password)
        ->expectsQuestion('Confirm password', $password)
        ->expectsOutput('Administrator created. Store the password securely.')
        ->assertSuccessful();

    $administrator = User::query()->where('email', 'command-line.admin@example.test')->firstOrFail();

    expect($administrator->name)->toBe('Command Line Administrator')
        ->and($administrator->is_active)->toBeTrue()
        ->and($administrator->hasVerifiedEmail())->toBeTrue()
        ->and($administrator->hasRole(Role::SUPER_ADMIN, 'web'))->toBeTrue()
        ->and(Hash::check($password, $administrator->password))->toBeTrue();
});
