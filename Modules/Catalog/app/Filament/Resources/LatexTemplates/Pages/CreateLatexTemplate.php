<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\LatexTemplates\Pages;

use Modules\Catalog\Filament\Resources\LatexTemplates\LatexTemplateResource;
use Modules\Catalog\Filament\Resources\Pages\CreateCatalogRecord;

class CreateLatexTemplate extends CreateCatalogRecord
{
    protected static string $resource = LatexTemplateResource::class;
}
