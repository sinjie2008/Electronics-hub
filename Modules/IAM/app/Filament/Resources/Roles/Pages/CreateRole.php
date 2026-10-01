<?php

namespace Modules\IAM\Filament\Resources\Roles\Pages;

use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Modules\IAM\Filament\Resources\Roles\RoleResource;
use Modules\IAM\Services\RoleManagementService;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $actor = Auth::user();

        if (! $actor instanceof User) {
            throw new AuthorizationException;
        }

        return app(RoleManagementService::class)->create($actor, $data);
    }
}
