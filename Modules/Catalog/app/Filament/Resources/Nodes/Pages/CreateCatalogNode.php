<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\Nodes\Pages;

use Modules\Catalog\Filament\Resources\Nodes\CatalogNodeResource;
use Modules\Catalog\Filament\Resources\Pages\CreateCatalogRecord;

class CreateCatalogNode extends CreateCatalogRecord
{
    protected static string $resource = CatalogNodeResource::class;
}
