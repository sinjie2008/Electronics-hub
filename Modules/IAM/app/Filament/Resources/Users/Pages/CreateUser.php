<?php

namespace Modules\IAM\Filament\Resources\Users\Pages;

use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Modules\IAM\Filament\Resources\Users\UserResource;
use Modules\IAM\Services\UserManagementService;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $actor = Auth::user();

        if (! $actor instanceof User) {
            throw new AuthorizationException;
        }

        return app(UserManagementService::class)->create($actor, $data);
    }
}
