<?php

namespace Modules\IAM\Filament\Resources\Permissions\Pages;

use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Modules\IAM\Filament\Resources\Permissions\PermissionResource;
use Modules\IAM\Services\PermissionManagementService;

class CreatePermission extends CreateRecord
{
    protected static string $resource = PermissionResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $actor = Auth::user();

        if (! $actor instanceof User) {
            throw new AuthorizationException;
        }

        return app(PermissionManagementService::class)->create($actor, $data);
    }
}
