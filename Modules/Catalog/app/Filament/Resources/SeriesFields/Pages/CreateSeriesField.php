<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\SeriesFields\Pages;

use Modules\Catalog\Filament\Resources\Pages\CreateCatalogRecord;
use Modules\Catalog\Filament\Resources\SeriesFields\SeriesFieldResource;

class CreateSeriesField extends CreateCatalogRecord
{
    protected static string $resource = SeriesFieldResource::class;
}
