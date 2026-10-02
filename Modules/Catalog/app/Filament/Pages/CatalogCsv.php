<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Modules\Catalog\Filament\CatalogPage;

class CatalogCsv extends CatalogPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static ?string $navigationLabel = 'CSV Import/Export';

    protected static ?string $title = 'Catalog CSV Import/Export';

    protected static ?string $slug = 'catalog/csv';

    protected static ?int $navigationSort = 30;

    protected static string $document = 'catalog-csv';
}
