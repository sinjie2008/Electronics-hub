<?php

declare(strict_types=1);

namespace Modules\Catalog\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Catalog\Http\Controllers\CatalogController;
use Modules\Catalog\Http\Controllers\CatalogOperationsController;
use Modules\Catalog\Http\Controllers\CatalogReadController;
use Modules\Catalog\Http\Controllers\LatexController;
use Modules\Catalog\Http\Controllers\SpecSearchController;
use Modules\Catalog\Http\Controllers\TypstController;
use Modules\Catalog\Http\CorrelationId;
use Modules\Catalog\Http\HttpRequestReader;
use Modules\Catalog\Http\HttpResponder;
use Modules\Catalog\Http\RequestInput;
use Modules\Catalog\Models\CatalogNode;
use Modules\Catalog\Models\CatalogProduct;
use Modules\Catalog\Models\LatexTemplate;
use Modules\Catalog\Models\SeriesField;
use Modules\Catalog\Models\TypstTemplate;
use Modules\Catalog\Policies\CatalogPolicy;
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
use Modules\Catalog\Services\PublicProductApiService;
use Modules\Catalog\Services\SeriesAttributeService;
use Modules\Catalog\Services\SeriesFieldService;
use Modules\Catalog\Services\SpecSearchService;
use Modules\Catalog\Services\TypstService;
use Modules\Catalog\Support\Config;
use Modules\Catalog\Support\Db;
use Nwidart\Modules\Support\ModuleServiceProvider;

/** Loaded exclusively by nWidart when Catalog is enabled. */
final class CatalogServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Catalog';

    protected string $nameLower = 'catalog';

    protected array $providers = [RouteServiceProvider::class];

    public function register(): void
    {
        if (! $this->app->configurationIsCached()) {
            $defaults = require dirname(__DIR__, 2).'/config/config.php';
            $settings = Config::combine($defaults, (array) config('catalog', []));
            $this->app['config']->set('catalog', $settings);
        }
        $this->app->scoped('catalog.connection', fn () => Db::fromHost());
        $this->app->scoped(PublicProductApiService::class, fn ($app) => new PublicProductApiService($app->make('catalog.connection')));
        $this->app->scoped(RequestInput::class, function ($app): RequestInput {
            $input = new RequestInput($app['request']);
            $app->refresh('request', $input, 'setRequest');

            return $input;
        });
        foreach ([
            CatalogService::class, SpecSearchService::class, LatexService::class, TypstService::class,
            HierarchyService::class, SeriesFieldService::class, MediaStorageService::class,
            CatalogTruncateService::class, LatexTemplateService::class,
        ] as $service) {
            $this->app->scoped($service, fn ($app) => new $service($app->make('catalog.connection')));
        }
        $this->app->scoped(LegacySpecSearchService::class, fn () => new LegacySpecSearchService);
        $this->app->scoped(SeriesAttributeService::class, fn ($app) => new SeriesAttributeService(
            $app->make('catalog.connection'), $app->make(SeriesFieldService::class), $app->make(MediaStorageService::class)
        ));
        $this->app->scoped(ProductService::class, fn ($app) => new ProductService(
            $app->make('catalog.connection'), $app->make(SeriesFieldService::class), $app->make(MediaStorageService::class)
        ));
        $this->app->scoped(CatalogCsvService::class, fn ($app) => new CatalogCsvService(
            $app->make('catalog.connection'), $app->make(SeriesFieldService::class)
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
        $this->app->bind(CatalogController::class, fn ($app) => new CatalogController(
            $app->make(RequestInput::class), $app->make('catalog.connection'), new HttpResponder,
            $app->make(HttpRequestReader::class), $app->make(MediaStorageService::class),
            $app->make(HierarchyService::class), $app->make(SeriesFieldService::class),
            $app->make(SeriesAttributeService::class), $app->make(ProductService::class),
            $app->make(CatalogCsvService::class), $app->make(CatalogTruncateService::class),
            $app->make(PublicCatalogService::class), $app->make(LegacySpecSearchService::class),
            $app->make(LatexTemplateService::class), $app->make(LatexBuildService::class)
        ));
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
        parent::register();
    }

    /** Host values take precedence over defaults, including when config is cached. */
    protected function registerConfig(): void
    {
        $this->publishes([dirname(__DIR__, 2).'/config/config.php' => config_path('catalog.php')], 'catalog-config');
    }

    public function boot(): void
    {
        RateLimiter::for('catalog-public', fn (Request $request): Limit => Limit::perMinute(
            max(1, min(600, (int) config('catalog.public_api.requests_per_minute', 120)))
        )->by('catalog-public:'.$request->ip())->response(function (Request $request, array $headers) {
            $out = new HttpResponder;
            $out->setCorrelationId(CorrelationId::fromRequest($request));
            $response = $out->sendError('TOO_MANY_REQUESTS', 'Too many requests.', 429);
            $response->headers->add($headers);

            return $response;
        }));
        RateLimiter::for('catalog-management', fn (Request $request): Limit => Limit::perMinute(120)
            ->by('catalog-management:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        // The parent auto-loads migrations into the host ledger, which is incorrect for Catalog.
        $this->registerCommands();
        $this->registerCommandSchedules();
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        foreach ([CatalogNode::class, CatalogProduct::class, SeriesField::class, TypstTemplate::class, LatexTemplate::class] as $model) {
            Gate::policy($model, CatalogPolicy::class);
        }
        $this->publishes([
            dirname(__DIR__, 2).'/public/assets' => public_path('modules/catalog/assets'),
        ], 'catalog-assets');
    }
}
