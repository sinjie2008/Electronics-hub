<?php

namespace Modules\IAM\Services\Concerns;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

trait AuthorizesIamOperations
{
    protected function authorizeActor(User $actor): void
    {
        if ($actor->exists) {
            $actor->refresh();
        }

        if (! $actor->is_active) {
            throw new AuthorizationException;
        }
    }

    protected function authorize(User $actor, string $ability, Model|string $subject): void
    {
        $this->authorizeActor($actor);

        Gate::forUser($actor)->authorize($ability, $subject);
    }

    protected function isSuperAdmin(User $actor): bool
    {
        return $actor->is_active && $actor->can('access.super-admin');
    }
}
