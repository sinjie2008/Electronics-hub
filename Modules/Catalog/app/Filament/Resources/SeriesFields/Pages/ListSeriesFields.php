<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\SeriesFields\Pages;

use Modules\Catalog\Filament\Resources\Pages\ListCatalogRecords;
use Modules\Catalog\Filament\Resources\SeriesFields\SeriesFieldResource;

class ListSeriesFields extends ListCatalogRecords
{
    protected static string $resource = SeriesFieldResource::class;
}
