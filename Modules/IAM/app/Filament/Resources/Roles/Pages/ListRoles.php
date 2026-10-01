<?php

namespace Modules\IAM\Filament\Resources\Roles\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Modules\IAM\Filament\Resources\Roles\RoleResource;

class ListRoles extends ListRecords
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->authorize(fn (): bool => RoleResource::canCreate())
                ->url(fn (): string => RoleResource::getUrl('create'))
                ->label('Create role'),
        ];
    }
}
