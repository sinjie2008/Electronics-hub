<?php

namespace Modules\IAM\Filament\Resources\Users\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Modules\IAM\Filament\Resources\Users\UserResource;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->authorize(fn (): bool => UserResource::canCreate())
                ->url(fn (): string => UserResource::getUrl('create'))
                ->label('Create user'),
        ];
    }
}
