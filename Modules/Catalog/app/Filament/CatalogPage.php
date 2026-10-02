<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament;

use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Modules\Catalog\Http\Middleware\ModuleEnabled;
use Nwidart\Modules\Facades\Module;
use UnitEnum;

abstract class CatalogPage extends Page
{
    public const DOCUMENT_PAGES = [
        'catalog_ui' => Pages\ProductCatalog::class,
        'catalog-csv' => Pages\CatalogCsv::class,
        'spec-search' => Pages\SpecSearch::class,
        'latex-templating' => Pages\LatexTemplating::class,
        'global_typst_template' => Pages\GlobalTypstTemplate::class,
        'series_typst_template' => Pages\SeriesTypstTemplate::class,
    ];

    public const QUERY_PARAMETERS = ['category', 'series', 'product', 'series_id', 'seriesId'];

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static string|array $routeMiddleware = [ModuleEnabled::class];

    protected static string $document;

    protected string $view = 'catalog::filament.pages.catalog';

    public static function canAccess(): bool
    {
        $user = Auth::guard('web')->user();

        return Module::isEnabled('Catalog')
            && $user !== null
            && $user->canAccessPanel(Filament::getPanel('admin'))
            && $user->hasVerifiedEmail()
            && $user->can('catalog.view');
    }

    /** @return array<NavigationItem> */
    public static function getNavigationItems(): array
    {
        return array_map(
            fn (NavigationItem $item): NavigationItem => $item->visible(fn (): bool => static::canAccess()),
            parent::getNavigationItems(),
        );
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    /** Keep the existing Bootstrap styles and scripts inside their own document. */
    public function getCatalogContentUrl(): string
    {
        return route('catalog.'.static::$document, Arr::only(request()->query(), self::QUERY_PARAMETERS));
    }
}
