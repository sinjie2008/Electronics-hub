<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\Products\Pages;

use Modules\Catalog\Filament\Resources\Pages\ViewCatalogRecord;
use Modules\Catalog\Filament\Resources\Products\CatalogProductResource;

class ViewCatalogProduct extends ViewCatalogRecord
{
    protected static string $resource = CatalogProductResource::class;
}
