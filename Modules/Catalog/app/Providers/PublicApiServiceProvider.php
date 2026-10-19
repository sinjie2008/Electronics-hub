<?php

declare(strict_types=1);

namespace Modules\Catalog\Providers;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\ConfigurationUrlParser;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Exceptions\MalformedUrlException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Modules\Catalog\Http\Controllers\PublicApi\ProductApiController;
use Modules\Catalog\Http\CorrelationId;
use Modules\Catalog\Http\Requests\PublicApi\ProductApiInput;
use Modules\Catalog\Repositories\PublicApi\ProductApiRepository;
use Modules\Catalog\Services\PublicApi\ProductApiService;
use Modules\Catalog\Support\PublicApiConnection;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class PublicApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2).'/config/public-api.php', 'catalog.public_api');

        if (! $this->app->configurationIsCached()) {
            $database = (array) config('database.connections.'.config('catalog.connection'), []);
            $database = (new ConfigurationUrlParser)->parseConfiguration($database);
            $database = array_diff_key($database, array_flip(['url', 'read', 'write', 'sticky']));
            $database['username'] = config('catalog.public_api.database.username');
            $database['password'] = config('catalog.public_api.database.password');
            $database['options'] = array_replace((array) ($database['options'] ?? []), [
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
            ]);
            config(['database.connections.catalog_public_api' => $database]);
        }

        $this->app->bind(ProductApiRepository::class, fn ($app): ProductApiRepository => new ProductApiRepository(
            $app->make(PublicApiConnection::class)->connection()
        ));
        $this->app->bind(ProductApiService::class);
        $this->app->bind(ProductApiController::class, fn ($app): ProductApiController => new ProductApiController(
            fn (): ProductApiService => $app->make(ProductApiService::class),
            $app->make(ProductApiInput::class)
        ));
    }

    public function boot(): void
    {
        $isPublicApi = fn (Request $request): bool => $request->is('api/v1/catalog', 'api/v1/catalog/*');
        TrimStrings::skipWhen($isPublicApi);
        ConvertEmptyStringsToNull::skipWhen($isPublicApi);
        $handler = $this->app->make(ExceptionHandler::class);
        // Invalid UTF-8 is rejected by Laravel before route middleware executes.
        $handler->renderable(function (MalformedUrlException|BadRequestHttpException $exception, Request $request): ?JsonResponse {
            $path = $request->getPathInfo();
            if ($path !== '/api/v1/catalog' && ! str_starts_with($path, '/api/v1/catalog/')) {
                return null;
            }
            $available = app('modules')->isEnabled('Catalog') && config('catalog.public_api.enabled', false);
            $correlationId = CorrelationId::fromRequest($request);

            return new JsonResponse([
                'success' => false, 'errorCode' => $available ? 'INVALID_PATH' : 'API_UNAVAILABLE',
                'message' => $available ? 'A valid catalog path is required.' : 'The public product API is unavailable.',
                'details' => [], 'correlationId' => $correlationId,
            ], $available ? 422 : 503, [
                'X-Correlation-ID' => $correlationId, 'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store, private', 'Content-Type' => 'application/json; charset=utf-8',
            ]);
        });
    }
}
