<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

beforeEach(function () {
    $this->withoutVite();
});

it('authenticates ordinary session users and regenerates logout state', function () {
    $user = User::factory()->create(['password' => 'Session-Test-Pass-6532']);
    $this->post('/login', ['email' => $user->email, 'password' => 'Session-Test-Pass-6532'])->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
    $this->post('/logout')->assertRedirect('/');
    $this->assertGuest();
});

it('rejects inactive accounts and throttles repeated failed sign-in attempts', function () {
    $inactive = User::factory()->create(['is_active' => false, 'password' => 'Session-Test-Pass-6532']);
    $this->post('/login', ['email' => $inactive->email, 'password' => 'Session-Test-Pass-6532'])->assertSessionHasErrors('email');
    $this->assertGuest();
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->post('/login', ['email' => 'missing@example.test', 'password' => 'incorrect'])->assertSessionHasErrors('email');
    }
    $this->post('/login', ['email' => 'missing@example.test', 'password' => 'incorrect'])->assertSessionHasErrors(['email' => 'Too many login attempts. Try again shortly.']);
});

it('sends and consumes a real password reset token without administrative panel access', function () {
    Notification::fake();
    $user = User::factory()->create();
    $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });
    $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))->assertOk();
    $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'New-Session-Pass-8514', 'password_confirmation' => 'New-Session-Pass-8514'])->assertRedirect('/login');
    expect(Hash::check('New-Session-Pass-8514', $user->refresh()->password))->toBeTrue();
    $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'Again-Session-Pass-8514', 'password_confirmation' => 'Again-Session-Pass-8514'])->assertSessionHasErrors('email');
});

it('rejects invalid tokens and weak passwords without changing the password', function () {
    $user = User::factory()->create();
    $originalHash = $user->password;
    $token = Password::createToken($user);
    $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'weak', 'password_confirmation' => 'weak'])->assertSessionHasErrors('password');
    $this->post('/reset-password', ['token' => 'invalid', 'email' => $user->email, 'password' => 'Strong-Session-Pass-8542', 'password_confirmation' => 'Strong-Session-Pass-8542'])->assertSessionHasErrors('email');
    expect($user->refresh()->password)->toBe($originalHash);
});
