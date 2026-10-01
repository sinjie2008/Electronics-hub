<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Modules\IAM\Models\Role;

class InitialAdminSeeder extends Seeder
{
    public function run(): void
    {
        $values = config('enterprise.initial_admin', []);
        $values = [
            'name' => is_string($values['name'] ?? null) ? trim($values['name']) : ($values['name'] ?? null),
            'email' => is_string($values['email'] ?? null) ? Str::lower(trim($values['email'])) : ($values['email'] ?? null),
            'password' => $values['password'] ?? null,
        ];

        if (blank($values['name']) && blank($values['email']) && blank($values['password'])) {
            return;
        }

        $validated = Validator::make($values, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', Password::defaults()],
        ])->validate();

        DB::transaction(function () use ($validated): void {
            if (User::query()->whereRaw('LOWER(email) = ?', [$validated['email']])->exists()) {
                return;
            }

            $user = User::query()->create($validated);
            $user->forceFill(['email_verified_at' => now(), 'is_active' => true])->save();
            $user->assignRole(Role::SUPER_ADMIN);
            activity('administration')->performedOn($user)->event('user.created')
                ->withProperties(['source' => 'initial-admin'])->log('user.created');

        }, attempts: 3);
    }
}
