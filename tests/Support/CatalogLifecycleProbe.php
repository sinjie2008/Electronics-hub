<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Modules\Catalog\Filament\Resources\LatexTemplates\LatexTemplateResource;
use Modules\Catalog\Filament\Resources\Nodes\CatalogNodeResource;
use Modules\Catalog\Filament\Resources\Products\CatalogProductResource;
use Modules\Catalog\Filament\Resources\SeriesFields\SeriesFieldResource;
use Modules\Catalog\Filament\Resources\TypstTemplates\TypstTemplateResource;
use Modules\Catalog\Http\ModuleRoutes;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Runs real HTTP kernels with isolated module statuses and Laravel cache files. */
final readonly class CatalogLifecycleProbe
{
    public function __construct(private string $directory, private bool $cached) {}

    /** @return list<string> */
    public static function publicUris(): array
    {
        $series = PublicProductApiFixture::SERIES_PATH;

        return [
            '/api/', '/api/health', '/api/tree', '/api/resolve/'.$series,
            '/api/categories/electronics', '/api/series/'.$series,
            '/api/series/'.$series.'/fields', '/api/series/'.$series.'/parts',
            '/api/series/'.$series.'/facets', '/api/search?q=Alpha',
        ];
    }

    /** @return list<string> */
    public static function assetUris(): array
    {
        return ['/catalog/assets/js/catalog_ui.js', '/assets/js/catalog_ui.js', '/public/assets/js/catalog_ui.js'];
    }

    /** @return list<string> */
    public static function managementUris(): array
    {
        return array_values(array_diff(self::ownedUris(), self::publicUris(), self::assetUris(), ['/api/index.php', '/api/index.php/tree']));
    }

    /**
     * Enumerate legacy aliases independently of the route collection being tested.
     *
     * @return list<string>
     */
    public static function ownedUris(): array
    {
        $paths = array_keys(ModuleRoutes::ENDPOINTS);
        foreach (['root-categories', 'product-categories', 'facets', 'products'] as $name) {
            $paths[] = 'api/spec-search/'.$name;
            $paths[] = 'api/spec-search/'.$name.'/index.php';
        }
        $paths = [...$paths, ...ModuleRoutes::PAGES, 'assets/js/catalog_ui.js'];
        foreach (['media', 'latex-pdfs', 'typst-pdfs', 'typst-assets'] as $kind) {
            $paths[] = 'storage/'.$kind.'/lifecycle.pdf';
        }
        $uris = [...self::publicUris(), '/api/index.php', '/api/index.php/tree'];
        foreach (['catalog', '', 'public'] as $mount) {
            foreach ($paths as $path) {
                $uris[] = '/'.ltrim($mount.'/'.$path, '/');
            }
        }

        return array_values(array_unique($uris));
    }

    /** @return array<string, string> */
    public static function filamentUris(): array
    {
        $uris = [];
        foreach ([
            'node' => [CatalogNodeResource::class, 110],
            'product' => [CatalogProductResource::class, 2001],
            'field' => [SeriesFieldResource::class, 1001],
            'typst' => [TypstTemplateResource::class, 5001],
            'latex' => [LatexTemplateResource::class, 6001],
        ] as $kind => [$resource, $record]) {
            foreach (['index', 'create', 'view', 'edit'] as $page) {
                $uris[$kind.' '.$page] = (string) parse_url($resource::getUrl($page, ['record' => $record]), PHP_URL_PATH);
            }
        }

        return $uris;
    }

    public static function seedTemplatesAndFiles(string $storageRoot): void
    {
        DB::connection('catalog')->table('typst_templates')->insert([
            'id' => 5001, 'title' => 'Lifecycle Typst', 'typst_content' => '= Lifecycle', 'is_global' => 1,
        ]);
        DB::connection('catalog')->table('latex_template')->insert([
            'id' => 6001, 'title' => 'Lifecycle LaTeX', 'latex_source' => '\\documentclass{article}\\begin{document}Lifecycle\\end{document}',
        ]);
        foreach (['media', 'latex-pdfs', 'typst-pdfs', 'typst-assets'] as $kind) {
            File::ensureDirectoryExists($storageRoot.'/'.$kind);
            File::put($storageRoot.'/'.$kind.'/lifecycle.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
        }
    }

    /**
     * All table rows include migration ledgers; filesystem entries include every fixture byte.
     *
     * @return array{tables: array<string, string>, files: array<string, string>}
     */
    public static function fingerprint(string $storageRoot): array
    {
        $connection = DB::connection('catalog');
        $tables = [];
        foreach ($connection->select('SHOW TABLES') as $row) {
            $table = (string) array_values((array) $row)[0];
            $rows = array_map(static fn (object $record): string => json_encode($record, JSON_THROW_ON_ERROR), $connection->table($table)->get()->all());
            sort($rows, SORT_STRING);
            $tables[$table] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        }
        ksort($tables);
        $files = [];
        foreach (File::allFiles($storageRoot) as $file) {
            $files[$file->getRelativePathname()] = hash_file('sha256', $file->getPathname());
        }
        ksort($files);

        return ['tables' => $tables, 'files' => $files];
    }

    public function cacheEnabledApplication(): void
    {
        $configProcess = new Process([PHP_BINARY, 'artisan', 'config:cache', '--no-ansi', '--no-interaction'], base_path(), $this->environment());
        $configProcess->setTimeout(60);
        $configProcess->mustRun();
        File::put($this->directory.'/config.php', '<?php return '.var_export(config()->all(), true).';'.PHP_EOL);
        $process = new Process([PHP_BINARY, 'artisan', 'route:cache', '--no-ansi', '--no-interaction'], base_path(), $this->environment());
        $process->setTimeout(60);
        $process->mustRun();
        if (! File::exists($this->directory.'/routes.php')) {
            throw new RuntimeException('The lifecycle route cache was not generated.');
        }
    }

    /**
     * @param  list<string>  $uris
     * @return array<string, mixed>
     */
    public function request(array $uris): array
    {
        $code = <<<'PHP'
            require $argv[1].'/vendor/autoload.php';
            $app = require $argv[1].'/bootstrap/app.php';
            $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
            $settings = $input['settings'];
            $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\LoadConfiguration::class, function ($app) use ($settings): void {
                $app['config']->set($settings);
            });
            $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
            $responses = [];
            foreach ($input['uris'] as $uri) {
                $request = Illuminate\Http\Request::create($uri, 'GET', server: ['HTTP_ACCEPT' => str_starts_with($uri, '/api/') ? 'application/json' : 'text/html']);
                $response = $kernel->handle($request);
                $responses[$uri] = [
                    'status' => $response->getStatusCode(),
                    'location' => $response->headers->get('Location'),
                    'json' => $response instanceof Symfony\Component\HttpFoundation\BinaryFileResponse ? null : json_decode((string) $response->getContent(), true),
                ];
                $kernel->terminate($request, $response);
            }
            $protected = [];
            foreach (['Core', 'IAM', 'System'] as $name) {
                $protected[$name] = $app['modules']->isEnabled($name);
            }
            echo json_encode([
                'responses' => $responses,
                'catalog_enabled' => $app['modules']->isEnabled('Catalog'),
                'protected_modules' => $protected,
                'routes_cached' => $app->routesAreCached(),
                'config_cached' => $app->configurationIsCached(),
            ], JSON_THROW_ON_ERROR);
            PHP;
        $settings = [
            'modules.activators.file.statuses-file' => config('modules.activators.file.statuses-file'),
            'catalog.project_root_urls' => true,
            'catalog.storage_root' => config('catalog.storage_root'),
            'catalog.connection' => 'catalog',
            'catalog.public_api.requests_per_minute' => 10000,
            'database.connections.catalog' => config('database.connections.catalog'),
            'session.driver' => 'array',
            'cache.default' => 'array',
        ];
        $process = new Process([PHP_BINARY, '-r', $code, base_path()], base_path(), $this->environment());
        $process->setInput(json_encode(['settings' => $settings, 'uris' => $uris], JSON_THROW_ON_ERROR));
        $process->setTimeout(60);
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        return [
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => $this->directory.'/'.($this->cached ? 'config.php' : 'uncached-config.php'),
            'APP_ROUTES_CACHE' => $this->directory.'/'.($this->cached ? 'routes.php' : 'uncached-routes.php'),
            'MODULE_STATUSES_PATH' => (string) config('modules.activators.file.statuses-file'),
            'SESSION_DRIVER' => 'array',
            'CACHE_STORE' => 'array',
            'TELESCOPE_ENABLED' => 'false',
        ];
    }
}
