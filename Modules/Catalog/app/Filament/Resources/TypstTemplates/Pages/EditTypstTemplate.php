<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\TypstTemplates\Pages;

use Modules\Catalog\Filament\Resources\Pages\EditCatalogRecord;
use Modules\Catalog\Filament\Resources\TypstTemplates\TypstTemplateResource;

class EditTypstTemplate extends EditCatalogRecord
{
    protected static string $resource = TypstTemplateResource::class;
}
