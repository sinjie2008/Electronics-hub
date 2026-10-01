<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Modules\IAM\Models\Role;

#[Signature('app:create-admin')]
#[Description('Create an initial administrator using a hidden password prompt')]
class CreateAdmin extends Command
{
    public function handle(): int
    {
        $values = [
            'name' => trim((string) $this->ask('Administrator name')),
            'email' => Str::lower(trim((string) $this->ask('Administrator email'))),
            'password' => $this->secret('Password (12+ characters, mixed case, number and symbol)'),
            'password_confirmation' => $this->secret('Confirm password'),
        ];
        $validated = Validator::make($values, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ])->validate();

        DB::transaction(function () use ($validated): void {
            app(RolesAndPermissionsSeeder::class)->run();

            if (User::query()->whereRaw('LOWER(email) = ?', [$validated['email']])->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'An account with this email address already exists.',
                ]);
            }

            $user = User::query()->create($validated);
            $user->forceFill(['email_verified_at' => now(), 'is_active' => true])->save();
            $user->assignRole(Role::SUPER_ADMIN);
            activity('administration')->performedOn($user)->event('user.created')
                ->withProperties(['source' => 'administrator-command'])->log('user.created');
        }, attempts: 3);

        $this->info('Administrator created. Store the password securely.');

        return self::SUCCESS;
    }
}
