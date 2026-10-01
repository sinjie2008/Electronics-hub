<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\TypstTemplates\Pages;

use Modules\Catalog\Filament\Resources\Pages\ListCatalogRecords;
use Modules\Catalog\Filament\Resources\TypstTemplates\TypstTemplateResource;

class ListTypstTemplates extends ListCatalogRecords
{
    protected static string $resource = TypstTemplateResource::class;
}
