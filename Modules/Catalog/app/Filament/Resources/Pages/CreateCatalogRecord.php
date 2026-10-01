<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Catalog\Filament\Resources\CatalogResource;

abstract class CreateCatalogRecord extends CreateRecord
{
    protected ?bool $hasDatabaseTransactions = false;

    protected function handleRecordCreation(array $data): Model
    {
        $resource = static::getResource();
        if (! is_a($resource, CatalogResource::class, true)) {
            abort(404);
        }

        return $resource::saveRecord(CatalogResource::authenticatedUser(), $data);
    }
}
