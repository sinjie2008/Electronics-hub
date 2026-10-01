<?php

namespace Modules\IAM\Filament\Resources\Permissions\Pages;

use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Modules\IAM\Filament\Resources\Permissions\PermissionResource;
use Modules\IAM\Models\Permission;
use Modules\IAM\Services\PermissionManagementService;

class EditPermission extends EditRecord
{
    protected static string $resource = PermissionResource::class;

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        $permission = $this->getRecord();

        abort_unless(
            $permission instanceof Permission
                && ! $permission->is_system
                && $permission->name !== 'access.super-admin',
            403,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $permission = $this->getRecord();

        if (! $permission instanceof Permission) {
            throw new AuthorizationException;
        }

        return ['name' => $permission->name];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = Auth::user();

        if (! $actor instanceof User || ! $record instanceof Permission) {
            throw new AuthorizationException;
        }

        return app(PermissionManagementService::class)->update($actor, $record, $data);
    }
}
