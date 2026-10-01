<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\Products\Pages;

use Modules\Catalog\Filament\Resources\Pages\ListCatalogRecords;
use Modules\Catalog\Filament\Resources\Products\CatalogProductResource;

class ListCatalogProducts extends ListCatalogRecords
{
    protected static string $resource = CatalogProductResource::class;
}
