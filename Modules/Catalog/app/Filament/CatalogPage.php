<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament;

use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
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

    public function getSubheading(): ?string
    {
        return match (static::$document) {
            'catalog_ui' => 'Manage the catalog hierarchy, attributes, and LaTeX exports.',
            'catalog-csv' => 'Export, import, restore, and audit CSV catalog snapshots.',
            'spec-search' => 'Find products by category and specification.',
            'latex-templating' => 'Manage LaTeX templates, preview them live, and export PDFs.',
            'global_typst_template' => 'Manage global templates and variables using Typst.',
            'series_typst_template' => 'Generate PDF for a specific series using Typst.',
            default => null,
        };
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    public function getCatalogPageKey(): string
    {
        return match (static::$document) {
            'catalog_ui' => 'product-catalog',
            'catalog-csv' => 'csv',
            'global_typst_template' => 'global-typst-template',
            'series_typst_template' => 'series-typst-template',
            default => static::$document,
        };
    }

    public function getCatalogView(): string
    {
        return 'catalog::filament.pages.'.$this->getCatalogPageKey();
    }

    /** @return array{page: string, apiBaseUrl: string, catalogUrl: string, seriesTemplateUrl: string, query: array<string, scalar>, csrfToken: string} */
    public function getWorkspaceConfiguration(): array
    {
        return [
            'page' => $this->getCatalogPageKey(),
            'apiBaseUrl' => rtrim(dirname(route('catalog.catalog-php', absolute: false)), '/').'/',
            'catalogUrl' => Pages\ProductCatalog::getUrl(panel: 'admin'),
            'seriesTemplateUrl' => Pages\SeriesTypstTemplate::getUrl(panel: 'admin'),
            'query' => array_filter(Arr::only(request()->query(), self::QUERY_PARAMETERS), is_scalar(...)),
            'csrfToken' => csrf_token(),
        ];
    }
}
