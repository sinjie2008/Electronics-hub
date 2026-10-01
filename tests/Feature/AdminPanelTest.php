<?php

use App\Models\User;
use App\Providers\AppServiceProvider;
use Filament\Auth\Notifications\ResetPassword as FilamentResetPasswordNotification;
use Filament\Auth\Notifications\VerifyEmail as FilamentVerifyEmailNotification;
use Filament\Auth\Pages\EditProfile;
use Filament\Auth\Pages\EmailVerification\EmailVerificationPrompt;
use Filament\Auth\Pages\Login;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification as MailNotification;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Client;
use Laravel\Telescope\TelescopeServiceProvider;
use Livewire\Livewire;
use Modules\IAM\Models\Permission;
use Modules\System\Filament\Pages\OAuthClients;
use Modules\System\Services\OAuthClientManager;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed();
    $this->withoutVite();
});

it('authenticates permitted administrators through the Filament Livewire login page', function () {
    $admin = adminPanelUserWithPermissions('access.admin');

    Livewire::test(Login::class)
        ->fillForm([
            'email' => $admin->email,
            'password' => 'password',
        ])
        ->call('authenticate')
        ->assertRedirect('/admin');

    $this->assertAuthenticatedAs($admin, 'web');
});

it('rejects Filament login for ordinary users and renders the reset request form', function () {
    $ordinaryUser = User::factory()->create();

    Livewire::test(Login::class)
        ->fillForm([
            'email' => $ordinaryUser->email,
            'password' => 'password',
        ])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $this->assertGuest('web');
    $this->get('/admin/password-reset/request')->assertOk();
});

it('sends a real Filament password reset notification and resets the password using its token', function () {
    $admin = adminPanelUserWithPermissions('access.admin');
    MailNotification::fake();

    Livewire::test(RequestPasswordReset::class)
        ->fillForm(['email' => $admin->email])
        ->call('request');

    MailNotification::assertSentToOnce($admin, FilamentResetPasswordNotification::class);
    $resetNotification = MailNotification::sent($admin, FilamentResetPasswordNotification::class)->sole();
    $token = $resetNotification->token;
    $newPassword = 'New-Valid-Admin-Password-9043!';

    expect($token)->toBeString()->not->toBeEmpty()
        ->and(DB::table('password_reset_tokens')->where('email', $admin->email)->exists())->toBeTrue();

    Livewire::test(ResetPassword::class, ['email' => $admin->email, 'token' => $token])
        ->fillForm([
            'password' => $newPassword,
            'passwordConfirmation' => $newPassword,
        ])
        ->call('resetPassword')
        ->assertRedirect('/admin/login');

    expect(Hash::check($newPassword, $admin->fresh()->password))->toBeTrue()
        ->and(DB::table('password_reset_tokens')->where('email', $admin->email)->exists())->toBeFalse();
});

it('requires the current password before changing it from the Filament profile', function () {
    $admin = adminPanelUserWithPermissions('access.admin');
    $newPassword = 'Another-Valid-Admin-Password-7412!';

    $profile = Livewire::actingAs($admin, 'web')->test(EditProfile::class)
        ->fillForm([
            'password' => $newPassword,
            'passwordConfirmation' => $newPassword,
            'currentPassword' => 'incorrect-current-password',
        ])
        ->call('save')
        ->assertHasFormErrors(['currentPassword']);

    expect(Hash::check('password', $admin->fresh()->password))->toBeTrue();

    $profile->fillForm(['currentPassword' => 'password'])->call('save')->assertHasNoFormErrors();

    expect(Hash::check($newPassword, $admin->fresh()->password))->toBeTrue();
});

it('redirects unverified administrators to verification, sends the panel link, and fulfills it', function () {
    $admin = adminPanelUserWithPermissions('access.admin');
    $admin->forceFill(['email_verified_at' => null])->save();

    $this->actingAs($admin, 'web')
        ->get('/admin/system/information')
        ->assertRedirect('/admin/email-verification/prompt');

    MailNotification::fake();
    Livewire::actingAs($admin, 'web')
        ->test(EmailVerificationPrompt::class)
        ->callAction('resendNotification');

    MailNotification::assertSentToOnce($admin, FilamentVerifyEmailNotification::class);
    $verificationNotification = MailNotification::sent($admin, FilamentVerifyEmailNotification::class)->sole();

    $this->actingAs($admin, 'web')
        ->get($verificationNotification->url)
        ->assertRedirect('/admin/system/information');

    expect($admin->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('renders the dashboard, authorized System pages, IAM resources, and administrator profile', function () {
    $admin = adminPanelUserWithPermissions(
        'access.admin',
        'users.view',
        'roles.view',
        'permissions.view',
        'activity-log.view',
        'backups.view',
        'system-info.view',
        'modules.view',
        'oauth-clients.view',
        'settings.view',
    );

    $this->actingAs($admin, 'web');

    foreach ([
        '/admin',
        '/admin/users',
        '/admin/roles',
        '/admin/permissions',
        '/admin/system/activity-log',
        '/admin/system/backups',
        '/admin/system/information',
        '/admin/system/modules',
        '/admin/system/oauth-clients',
        '/admin/system/settings',
        '/admin/profile',
    ] as $uri) {
        $this->get($uri)->assertOk();
    }
});

it('hides unauthorized navigation, forbids page routes, and blocks forged OAuth revoke actions', function () {
    $creator = adminPanelUserWithPermissions('access.admin', 'oauth-clients.view', 'oauth-clients.create');
    $client = app(OAuthClientManager::class)->createClientCredentialsClient($creator, 'Protected integration');

    $limitedAdmin = adminPanelUserWithPermissions('access.admin');
    $this->actingAs($limitedAdmin, 'web')
        ->get('/admin')
        ->assertOk()
        ->assertDontSee('Settings')
        ->assertDontSee('System Information')
        ->assertDontSee('OAuth Clients');

    $this->get('/admin/users')->assertForbidden();
    $this->get('/admin/roles')->assertForbidden();
    $this->get('/admin/permissions')->assertForbidden();
    $this->get('/admin/system/settings')->assertForbidden();
    $this->get('/admin/system/information')->assertForbidden();
    $this->get('/admin/system/activity-log')->assertForbidden();
    $this->get('/admin/system/backups')->assertForbidden();
    $this->get('/admin/system/modules')->assertForbidden();
    $this->get('/admin/system/oauth-clients')->assertForbidden();

    $viewer = adminPanelUserWithPermissions('access.admin', 'oauth-clients.view');
    $this->actingAs($viewer, 'web')
        ->get('/admin/system/oauth-clients')
        ->assertOk()
        ->assertSee('Protected integration')
        ->assertDontSee('Revoke client');

    Livewire::actingAs($viewer, 'web')
        ->test(OAuthClients::class)
        ->call('revokeClient', (string) $client->getKey())
        ->assertForbidden();

    expect($client->fresh()->revoked)->toBeFalse();
});

it('downloads machine credentials once and omits the secret from later UI and audit data', function () {
    $admin = adminPanelUserWithPermissions('access.admin', 'oauth-clients.view', 'oauth-clients.create');

    $component = Livewire::actingAs($admin, 'web')
        ->test(OAuthClients::class)
        ->callAction('create', [
            'name' => 'One time machine client',
            'grant_type' => 'client_credentials',
        ])
        ->assertFileDownloaded(contentType: 'application/json; charset=utf-8');

    $download = json_decode(base64_decode($component->effects['download']['content'], true), true, flags: JSON_THROW_ON_ERROR);
    $client = Client::query()->findOrFail($download['client_id']);
    $secret = $download['client_secret'];
    $activity = Activity::query()
        ->where('subject_type', Client::class)
        ->where('subject_id', $client->getKey())
        ->where('event', 'oauth-client.created')
        ->sole();

    expect($download['grant_type'])->toBe('client_credentials')
        ->and($secret)->toBeString()
        ->and(strlen($secret))->toBeGreaterThan(0)
        ->and(str_contains($component->html(), $secret))->toBeFalse()
        ->and(str_contains($activity->properties->toJson(), $secret))->toBeFalse();

    $this->actingAs($admin, 'web')
        ->get('/admin/system/oauth-clients')
        ->assertOk()
        ->assertSee('One time machine client')
        ->assertDontSee($secret);
});

it('rejects forged OAuth grant types before creating a client', function () {
    $admin = adminPanelUserWithPermissions('access.admin', 'oauth-clients.view', 'oauth-clients.create');

    Livewire::actingAs($admin, 'web')
        ->test(OAuthClients::class)
        ->callAction('create', [
            'name' => 'Unsupported grant client',
            'grant_type' => 'password',
            'redirect_uri' => 'https://client.example.test/callback',
        ])
        ->assertHasFormErrors(['grant_type'])
        ->assertNoFileDownloaded();

    expect(Client::query()->where('name', 'Unsupported grant client')->exists())->toBeFalse();
});

it('logs an administrator out and rejects inactive administrators from panel routes', function () {
    $admin = adminPanelUserWithPermissions('access.admin');

    $this->actingAs($admin, 'web')
        ->post('/admin/logout')
        ->assertRedirect('/admin/login');

    $this->assertGuest('web');

    $inactiveAdmin = adminPanelUserWithPermissions('access.admin');
    $inactiveAdmin->forceFill(['is_active' => false])->save();
    $this->actingAs($inactiveAdmin, 'web')
        ->get('/admin/system/information')
        ->assertForbidden();
});

it('does not register Telescope routes in production even if Telescope is enabled', function () {
    $this->app->detectEnvironment(fn (): string => 'production');
    config(['telescope.enabled' => true]);

    (new AppServiceProvider($this->app))->register();

    expect(app()->environment())->toBe('production')
        ->and(app()->providerIsLoaded(TelescopeServiceProvider::class))->toBeFalse()
        ->and(Route::has('telescope'))->toBeFalse();

    $this->get('/telescope')->assertNotFound();
});

function adminPanelUserWithPermissions(string ...$permissionNames): User
{
    $user = User::factory()->create();

    foreach ($permissionNames as $permissionName) {
        $user->givePermissionTo(Permission::findOrCreate($permissionName, 'web'));
    }

    return $user;
}
