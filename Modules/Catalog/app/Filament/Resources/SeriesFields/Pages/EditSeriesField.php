<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\SeriesFields\Pages;

use Modules\Catalog\Filament\Resources\Pages\EditCatalogRecord;
use Modules\Catalog\Filament\Resources\SeriesFields\SeriesFieldResource;

class EditSeriesField extends EditCatalogRecord
{
    protected static string $resource = SeriesFieldResource::class;
}
