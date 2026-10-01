<?php

namespace Modules\IAM\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Collection;
use Modules\IAM\Policies\RolePolicy;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * @property int|string $id
 * @property string $name
 * @property string $guard_name
 * @property bool $is_system
 * @property-read Collection<int, Permission> $permissions
 * @property-read Collection<int, User> $users
 */
#[UsePolicy(RolePolicy::class)]
class Role extends SpatieRole
{
    public const SUPER_ADMIN = 'Super Admin';

    public const ADMIN = 'Admin';

    public const USER = 'User';

    public static function isReservedName(string $name): bool
    {
        $reservedNames = array_map(strtolower(...), [self::SUPER_ADMIN, self::ADMIN, self::USER]);

        return in_array(strtolower($name), $reservedNames, true);
    }

    public function isProtected(): bool
    {
        return $this->is_system || self::isReservedName($this->name);
    }

    /**
     * A role is privileged when it can confer administrative identity. The
     * base User role is the only system role ordinary administrators may grant.
     */
    public function isPrivileged(): bool
    {
        return strtolower($this->name) !== strtolower(self::USER)
            && ($this->is_system || in_array(strtolower($this->name), [strtolower(self::SUPER_ADMIN), strtolower(self::ADMIN)], true));
    }

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
