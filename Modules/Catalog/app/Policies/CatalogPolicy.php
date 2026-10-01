<?php

declare(strict_types=1);

namespace Modules\Catalog\Policies;

use App\Models\User;
use Modules\Catalog\Models\CatalogModel;

class CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view');
    }

    public function view(User $user, CatalogModel $record): bool
    {
        return $this->allows($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'view') && $this->allows($user, 'create');
    }

    public function update(User $user, CatalogModel $record): bool
    {
        return $this->allows($user, 'view') && $this->allows($user, 'update');
    }

    public function delete(User $user, CatalogModel $record): bool
    {
        return $this->allows($user, 'view') && $this->allows($user, 'delete');
    }

    private function allows(User $user, string $operation): bool
    {
        return app('modules')->isEnabled('Catalog') && $user->is_active && $user->can('catalog.'.$operation);
    }
}
