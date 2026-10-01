<?php

namespace Modules\IAM\Filament\Resources\Users\Pages;

use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Modules\IAM\Filament\Resources\Users\UserResource;
use Modules\IAM\Services\UserManagementService;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $user = $this->getRecord();

        if (! $user instanceof User) {
            throw new AuthorizationException;
        }

        $actor = Auth::user();

        $formData = [
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => $user->is_active,
        ];

        if ($actor instanceof User && ($actor->can('access.super-admin') || ! $actor->is($user))) {
            $formData['role_ids'] = $user->roles()->pluck('roles.id')->all();
        }

        return $formData;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = Auth::user();

        if (! $actor instanceof User || ! $record instanceof User) {
            throw new AuthorizationException;
        }

        return app(UserManagementService::class)->update($actor, $record, $data);
    }
}
