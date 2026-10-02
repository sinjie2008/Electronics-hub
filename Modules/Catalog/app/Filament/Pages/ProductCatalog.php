<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Modules\Catalog\Filament\CatalogPage;

class ProductCatalog extends CatalogPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $navigationLabel = 'Catalog UI';

    protected static ?string $title = 'Product Catalog Manager';

    protected static ?string $slug = 'catalog';

    protected static ?int $navigationSort = 10;

    protected static string $document = 'catalog_ui';
}
