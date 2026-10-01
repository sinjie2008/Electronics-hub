<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\TypstTemplates\Pages;

use Modules\Catalog\Filament\Resources\Pages\CreateCatalogRecord;
use Modules\Catalog\Filament\Resources\TypstTemplates\TypstTemplateResource;

class CreateTypstTemplate extends CreateCatalogRecord
{
    protected static string $resource = TypstTemplateResource::class;
}
