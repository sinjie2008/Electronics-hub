<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\SeriesFields\Pages;

use Modules\Catalog\Filament\Resources\Pages\ViewCatalogRecord;
use Modules\Catalog\Filament\Resources\SeriesFields\SeriesFieldResource;

class ViewSeriesField extends ViewCatalogRecord
{
    protected static string $resource = SeriesFieldResource::class;
}
