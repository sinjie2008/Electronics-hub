<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\LatexTemplates\Pages;

use Modules\Catalog\Filament\Resources\LatexTemplates\LatexTemplateResource;
use Modules\Catalog\Filament\Resources\Pages\EditCatalogRecord;

class EditLatexTemplate extends EditCatalogRecord
{
    protected static string $resource = LatexTemplateResource::class;
}
