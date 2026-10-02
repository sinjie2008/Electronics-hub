<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Modules\Catalog\Filament\CatalogPage;

class LatexTemplating extends CatalogPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'LaTeX Templating';

    protected static ?string $title = 'LaTeX Templates';

    protected static ?string $slug = 'catalog/latex-templating';

    protected static ?int $navigationSort = 40;

    protected static string $document = 'latex-templating';
}
