<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Modules\Catalog\Filament\CatalogPage;

class SpecSearch extends CatalogPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static ?string $navigationLabel = 'Spec Search';

    protected static ?string $title = 'Product Search / Spec Search';

    protected static ?string $slug = 'catalog/spec-search';

    protected static ?int $navigationSort = 20;

    protected static string $document = 'spec-search';
}
