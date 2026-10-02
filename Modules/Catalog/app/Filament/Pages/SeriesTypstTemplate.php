<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Modules\Catalog\Filament\CatalogPage;

class SeriesTypstTemplate extends CatalogPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocument;

    protected static ?string $navigationLabel = 'Series Typst Template';

    protected static ?string $title = 'Series Typst Template';

    protected static ?string $slug = 'catalog/series-typst-template';

    protected static ?int $navigationSort = 60;

    protected static string $document = 'series_typst_template';
}
