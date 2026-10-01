<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

abstract class ListCatalogRecords extends ListRecords
{
    protected function getHeaderActions(): array
    {
        $resource = static::getResource();

        return [
            Action::make('create')
                ->authorize(fn (): bool => $resource::canCreate())
                ->url(fn (): string => $resource::getUrl('create'))
                ->label('Create '.$resource::getModelLabel()),
        ];
    }
}
