<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\TypstTemplates\Pages;

use Modules\Catalog\Filament\Resources\Pages\ViewCatalogRecord;
use Modules\Catalog\Filament\Resources\TypstTemplates\TypstTemplateResource;

class ViewTypstTemplate extends ViewCatalogRecord
{
    protected static string $resource = TypstTemplateResource::class;
}
