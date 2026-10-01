<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\Products\Pages;

use Modules\Catalog\Filament\Resources\Pages\CreateCatalogRecord;
use Modules\Catalog\Filament\Resources\Products\CatalogProductResource;

class CreateCatalogProduct extends CreateCatalogRecord
{
    protected static string $resource = CatalogProductResource::class;
}
