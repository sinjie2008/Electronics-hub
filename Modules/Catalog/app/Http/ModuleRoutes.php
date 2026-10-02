<?php

declare(strict_types=1);

namespace Modules\Catalog\Http;

use Illuminate\Support\Facades\Route;
use Modules\Catalog\Http\Controllers\CatalogController;
use Modules\Catalog\Http\Controllers\CatalogOperationsController;
use Modules\Catalog\Http\Controllers\CatalogReadController;
use Modules\Catalog\Http\Controllers\LatexController;
use Modules\Catalog\Http\Controllers\PageController;
use Modules\Catalog\Http\Controllers\SpecSearchController;
use Modules\Catalog\Http\Controllers\StorageController;
use Modules\Catalog\Http\Controllers\TypstController;
use Modules\Catalog\Http\Middleware\CatalogAccess;
use Modules\Catalog\Http\Middleware\ModuleEnabled;

/** Compatibility route definitions; every action receives a native Request. */
final class ModuleRoutes
{
    public const ENDPOINTS = [
        'catalog.php' => [CatalogController::class, 'run'],
        'api/catalog/hierarchy.php' => [CatalogReadController::class, 'hierarchy'],
        'api/catalog/search.php' => [CatalogReadController::class, 'search'],
        'api/catalog/csv-download.php' => [CatalogOperationsController::class, 'csvDownload'],
        'api/catalog/csv-export.php' => [CatalogOperationsController::class, 'csvExport'],
        'api/catalog/csv-history.php' => [CatalogOperationsController::class, 'csvHistory'],
        'api/catalog/csv-import.php' => [CatalogOperationsController::class, 'csvImport'],
        'api/catalog/csv-restore.php' => [CatalogOperationsController::class, 'csvRestore'],
        'api/catalog/truncate.php' => [CatalogOperationsController::class, 'truncate'],
        'api/catalog/pdf.php' => [CatalogOperationsController::class, 'pdf'],
        'api/series/details.php' => [CatalogReadController::class, 'seriesDetails'],
        'api/spec-search/root-categories.php' => [SpecSearchController::class, 'rootCategories'],
        'api/spec-search/product-categories.php' => [SpecSearchController::class, 'productCategories'],
        'api/spec-search/facets.php' => [SpecSearchController::class, 'facets'],
        'api/spec-search/products.php' => [SpecSearchController::class, 'products'],
        'api/latex/compile.php' => [LatexController::class, 'compile'],
        'api/latex/templates.php' => [LatexController::class, 'templates'],
        'api/latex/variables.php' => [LatexController::class, 'variables'],
        'api/typst/compile.php' => [TypstController::class, 'compile'],
        'api/typst/templates.php' => [TypstController::class, 'templates'],
        'api/typst/variables.php' => [TypstController::class, 'variables'],
        'api/typst/series-preferences.php' => [TypstController::class, 'seriesPreferences'],
        'legacy/api/catalog/hierarchy.php' => [CatalogReadController::class, 'hierarchy'],
        'legacy/api/catalog/search.php' => [CatalogReadController::class, 'search'],
        'legacy/api/spec-search/root-categories' => [SpecSearchController::class, 'rootCategories'],
        'legacy/api/spec-search/product-categories' => [SpecSearchController::class, 'productCategories'],
        'legacy/api/spec-search/facets' => [SpecSearchController::class, 'facets'],
        'legacy/api/spec-search/products' => [SpecSearchController::class, 'products'],
        'legacy/api/typst/compile.php' => [TypstController::class, 'compile'],
        'legacy/api/typst/templates.php' => [TypstController::class, 'legacyTemplates'],
        'legacy/api/typst/variables.php' => [TypstController::class, 'legacyVariables'],
    ];

    public const PAGES = [
        'catalog_ui.html', 'catalog-csv.html', 'spec-search.html',
        'latex-templating.html', 'global_typst_template.html', 'series_typst_template.html',
    ];

    public static function register(): void
    {
        $prefix = trim((string) config('catalog.prefix', 'catalog'), '/');
        self::mount($prefix, 'catalog.', false);
        if ($prefix !== '' && config('catalog.legacy_urls', true)) {
            self::mount('', 'catalog.compat.', config('catalog.root_typst_api') === 'root');
        }
        if (config('catalog.project_root_urls', false)) {
            self::mount('public', 'catalog.public.', false);
        }
    }

    private static function endpoints(): array
    {
        $endpoints = self::ENDPOINTS;
        foreach (['root-categories', 'product-categories', 'facets', 'products'] as $name) {
            $handler = self::ENDPOINTS['api/spec-search/'.$name.'.php'];
            $endpoints['api/spec-search/'.$name] = $handler;
            $endpoints['api/spec-search/'.$name.'/index.php'] = $handler;
        }

        return $endpoints;
    }

    public static function isApiPath(string $path): bool
    {
        $mounts = [trim((string) config('catalog.prefix', 'catalog'), '/')];
        if (config('catalog.legacy_urls', true)) {
            $mounts[] = '';
        }
        if (config('catalog.project_root_urls', false)) {
            $mounts[] = 'public';
        }
        foreach (array_unique($mounts) as $prefix) {
            foreach (array_keys(self::endpoints()) as $endpoint) {
                if (trim($path, '/') === trim($prefix.'/'.$endpoint, '/')) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function mount(string $prefix, string $namePrefix, bool $rootTypst): void
    {
        Route::prefix($prefix)->middleware(array_merge(
            [ModuleEnabled::class], (array) config('catalog.middleware', [])
        ))->group(function () use ($prefix, $namePrefix, $rootTypst): void {
            foreach (self::endpoints() as $path => $handler) {
                if ($rootTypst && $path === 'api/typst/templates.php') {
                    $handler = [TypstController::class, 'legacyTemplates'];
                } elseif ($rootTypst && $path === 'api/typst/variables.php') {
                    $handler = [TypstController::class, 'legacyVariables'];
                }
                $guard = $prefix === '' && str_starts_with($path, 'api/') ? 'api' : 'web';
                Route::any($path, $handler)->middleware([$guard === 'api' ? 'api' : 'web', CatalogAccess::class])
                    ->defaults('catalog_guard', $guard)->defaults('catalog_mount', $prefix)
                    ->name($namePrefix.str_replace(['/', '.'], ['.', '-'], $path));
            }
            foreach (self::PAGES as $page) {
                Route::match(['GET', 'HEAD'], $page, [PageController::class, 'show'])
                    ->middleware(['web', CatalogAccess::class])
                    ->defaults('catalog_page', substr($page, 0, -5))->defaults('catalog_mount', $prefix)
                    ->name($namePrefix.substr($page, 0, -5));
            }
            foreach (['assets', 'media', 'latex-pdfs', 'typst-pdfs', 'typst-assets'] as $kind) {
                $path = $kind === 'assets' ? 'assets/{path}' : 'storage/'.$kind.'/{path}';
                Route::match(['GET', 'HEAD'], $path, [StorageController::class, 'download'])
                    ->where('path', '.+')->defaults('catalog_storage', $kind)->defaults('catalog_mount', $prefix)
                    ->name($namePrefix.'files.'.$kind);
            }
        });
    }
}
