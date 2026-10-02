<?php

declare(strict_types=1);

namespace Modules\Catalog\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Catalog\Http\Controllers\CatalogController;
use Modules\Catalog\Http\Controllers\CatalogOperationsController;
use Modules\Catalog\Http\Controllers\CatalogReadController;
use Modules\Catalog\Http\Controllers\LatexController;
use Modules\Catalog\Http\Controllers\SpecSearchController;
use Modules\Catalog\Http\Controllers\TypstController;
use Modules\Catalog\Http\HttpRequestReader;
use Modules\Catalog\Http\HttpResponder;
use Modules\Catalog\Http\RequestInput;
use Modules\Catalog\Services\CatalogBootstrapService;
use Modules\Catalog\Services\CatalogCsvService;
use Modules\Catalog\Services\CatalogService;
use Modules\Catalog\Services\CatalogTruncateService;
use Modules\Catalog\Services\HierarchyService;
use Modules\Catalog\Services\LatexBuildService;
use Modules\Catalog\Services\LatexService;
use Modules\Catalog\Services\LatexTemplateService;
use Modules\Catalog\Services\LegacySpecSearchService;
use Modules\Catalog\Services\MediaStorageService;
use Modules\Catalog\Services\ProductService;
use Modules\Catalog\Services\PublicCatalogService;
use Modules\Catalog\Services\SeriesAttributeService;
use Modules\Catalog\Services\SeriesFieldService;
use Modules\Catalog\Services\SpecSearchService;
use Modules\Catalog\Services\TypstService;
use Modules\Catalog\Support\Config;
use Modules\Catalog\Support\Db;

/** Loaded exclusively by nWidart when Catalog is enabled. */
final class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->app->configurationIsCached()) {
            $defaults = require dirname(__DIR__, 2).'/config/config.php';
            $settings = Config::combine($defaults, (array) config('catalog', []));
            $this->app['config']->set('catalog', $settings);
            if (config('catalog.connection') === 'default') {
                $this->app['config']->set('catalog.connection', config('database.default'));
            }
            if (config('catalog.connection') === 'catalog') {
                $this->app['config']->set('database.connections.catalog', config('catalog.database'));
            }
        }
        $this->app->scoped(RequestInput::class, function ($app): RequestInput {
            $input = new RequestInput($app['request']);
            $app->refresh('request', $input, 'setRequest');

            return $input;
        });
        foreach ([
            CatalogService::class, SpecSearchService::class, LatexService::class, TypstService::class,
            HierarchyService::class, SeriesFieldService::class, MediaStorageService::class,
            CatalogTruncateService::class, LatexTemplateService::class, CatalogBootstrapService::class,
        ] as $service) {
            $this->app->scoped($service, fn ($app) => new $service(Db::connection()));
        }
        $this->app->scoped(LegacySpecSearchService::class, fn () => new LegacySpecSearchService);
        $this->app->scoped(SeriesAttributeService::class, fn ($app) => new SeriesAttributeService(
            Db::connection(), $app->make(SeriesFieldService::class), $app->make(MediaStorageService::class)
        ));
        $this->app->scoped(ProductService::class, fn ($app) => new ProductService(
            Db::connection(), $app->make(SeriesFieldService::class), $app->make(MediaStorageService::class)
        ));
        $this->app->scoped(CatalogCsvService::class, fn ($app) => new CatalogCsvService(
            Db::connection(), $app->make(SeriesFieldService::class)
        ));
        $this->app->scoped(PublicCatalogService::class, fn ($app) => new PublicCatalogService(
            $app->make(HierarchyService::class), $app->make(SeriesFieldService::class),
            $app->make(SeriesAttributeService::class), $app->make(ProductService::class)
        ));
        $this->app->scoped(LatexBuildService::class, fn () => new LatexBuildService(
            Config::get('app')['latex']['default_binary'],
            Config::get('app')['storage']['latex_pdfs'],
            Config::get('app')['storage']['latex_build']
        ));
        $this->app->bind(CatalogController::class, function ($app): CatalogController {
            return new CatalogController(
                $app->make(RequestInput::class), Db::connection(), new HttpResponder,
                $app->make(HttpRequestReader::class), $app->make(MediaStorageService::class),
                $app->make(HierarchyService::class), $app->make(SeriesFieldService::class),
                $app->make(SeriesAttributeService::class), $app->make(ProductService::class),
                $app->make(CatalogCsvService::class), $app->make(CatalogTruncateService::class),
                $app->make(PublicCatalogService::class), $app->make(LegacySpecSearchService::class),
                $app->make(LatexTemplateService::class), $app->make(LatexBuildService::class),
                $app->make(CatalogBootstrapService::class)
            );
        });
        foreach ([
            CatalogReadController::class => CatalogService::class,
            SpecSearchController::class => SpecSearchService::class,
            LatexController::class => LatexService::class,
            TypstController::class => TypstService::class,
        ] as $controller => $service) {
            $this->app->bind($controller, fn ($app) => new $controller(
                $app->make(RequestInput::class), null, fn () => $app->make($service)
            ));
        }
        $this->app->bind(CatalogOperationsController::class, fn ($app) => new CatalogOperationsController(
            $app->make(RequestInput::class), fn () => $app->make(CatalogController::class)
        ));
        $this->app->register(RouteServiceProvider::class);
    }

    /** Host values take precedence over defaults, including when config is cached. */
    protected function registerConfig(): void
    {
        $this->publishes([dirname(__DIR__, 2).'/config/config.php' => config_path('catalog.php')], 'catalog-config');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(dirname(__DIR__, 2).'/resources/views', 'catalog');
        $this->registerConfig();
        $this->registerBackupSources();
        foreach (['view', 'manage', 'csv', 'templates', 'truncate'] as $ability) {
            Gate::define('catalog.'.$ability, fn (User $user): bool => $user->is_active && $user->checkPermissionTo('catalog.'.$ability, 'web'));
        }
    }

    private function registerBackupSources(): void
    {
        $storage = config('catalog.storage_root') ?: storage_path('app/catalog');
        $databases = (array) config('backup.backup.source.databases', []);
        $directories = (array) config('backup.backup.source.files.include', []);
        $excludedDirectories = (array) config('backup.backup.source.files.exclude', []);
        config([
            'backup.backup.source.databases' => array_values(array_unique([...$databases, config('catalog.connection')])),
            'backup.backup.source.files.include' => array_values(array_unique([...$directories, $storage])),
            'backup.backup.source.files.exclude' => array_values(array_unique([
                ...$excludedDirectories, $storage.'/latex-build', $storage.'/typst-build',
            ])),
        ]);
    }
}
