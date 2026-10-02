<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Modules\Catalog\Filament\CatalogPage;

class GlobalTypstTemplate extends CatalogPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static ?string $navigationLabel = 'Global Typst Template';

    protected static ?string $title = 'Global Typst Template';

    protected static ?string $slug = 'catalog/global-typst-template';

    protected static ?int $navigationSort = 50;

    protected static string $document = 'global_typst_template';
}
