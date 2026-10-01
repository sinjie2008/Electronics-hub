<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\LatexTemplates\Pages;

use Modules\Catalog\Filament\Resources\LatexTemplates\LatexTemplateResource;
use Modules\Catalog\Filament\Resources\Pages\ListCatalogRecords;

class ListLatexTemplates extends ListCatalogRecords
{
    protected static string $resource = LatexTemplateResource::class;
}
