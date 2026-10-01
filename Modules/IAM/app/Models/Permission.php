<?php

namespace Modules\IAM\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Collection;
use Modules\IAM\Policies\PermissionPolicy;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * @property int|string $id
 * @property string $name
 * @property string $guard_name
 * @property bool $is_system
 * @property-read Collection<int, Role> $roles
 * @property-read Collection<int, User> $users
 */
#[UsePolicy(PermissionPolicy::class)]
class Permission extends SpatiePermission
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }
}
