<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\Pages;

use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Catalog\Filament\Resources\CatalogResource;
use Modules\Catalog\Models\CatalogModel;

abstract class EditCatalogRecord extends EditRecord
{
    protected ?bool $hasDatabaseTransactions = false;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        $resource = static::getResource();

        if (! $record instanceof CatalogModel || ! is_a($resource, CatalogResource::class, true)) {
            abort(404);
        }

        return $resource::mutateRecordFormData($data, $record);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $resource = static::getResource();

        if (! $record instanceof CatalogModel || ! is_a($resource, CatalogResource::class, true)) {
            abort(404);
        }

        return $resource::saveRecord(CatalogResource::authenticatedUser(), $data, $record);
    }
}
