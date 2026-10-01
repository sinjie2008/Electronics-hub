<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\Products\Pages;

use Modules\Catalog\Filament\Resources\Pages\EditCatalogRecord;
use Modules\Catalog\Filament\Resources\Products\CatalogProductResource;

class EditCatalogProduct extends EditCatalogRecord
{
    protected static string $resource = CatalogProductResource::class;
}
