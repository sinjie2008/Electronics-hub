<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\Nodes\Pages;

use Modules\Catalog\Filament\Resources\Nodes\CatalogNodeResource;
use Modules\Catalog\Filament\Resources\Pages\EditCatalogRecord;

class EditCatalogNode extends EditCatalogRecord
{
    protected static string $resource = CatalogNodeResource::class;
}
