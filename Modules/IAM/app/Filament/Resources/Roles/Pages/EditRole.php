<?php

namespace Modules\IAM\Filament\Resources\Roles\Pages;

use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Modules\IAM\Filament\Resources\Roles\RoleResource;
use Modules\IAM\Models\Role;
use Modules\IAM\Services\RoleManagementService;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $role = $this->getRecord();

        if (! $role instanceof Role) {
            throw new AuthorizationException;
        }

        return [
            'name' => $role->name,
            'permission_ids' => $role->permissions()->pluck('permissions.id')->all(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = Auth::user();

        if (! $actor instanceof User || ! $record instanceof Role) {
            throw new AuthorizationException;
        }

        return app(RoleManagementService::class)->update($actor, $record, $data);
    }
}
