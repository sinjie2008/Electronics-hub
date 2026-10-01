<?php

namespace Modules\IAM\Filament\Resources\Permissions\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Modules\IAM\Filament\Resources\Permissions\PermissionResource;

class ListPermissions extends ListRecords
{
    protected static string $resource = PermissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->authorize(fn (): bool => PermissionResource::canCreate())
                ->url(fn (): string => PermissionResource::getUrl('create'))
                ->label('Create permission'),
        ];
    }
}
