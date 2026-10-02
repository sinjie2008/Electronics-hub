<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Catalog\Http\HttpResponder;
use Modules\Catalog\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class CatalogAccess
{
    public function handle(Request $request, Closure $next): HttpResponse
    {
        $ability = $this->ability($request);
        $actor = $request->user($request->route('catalog_guard', 'web'));
        if ($actor === null && $request->route('catalog_page') !== null) {
            return redirect()->guest(route('filament.admin.auth.login'));
        }
        if ($actor === null
            || ! $actor->canAccessPanel(Filament::getPanel('admin'))
            || ! $actor->hasVerifiedEmail()
            || Gate::forUser($actor)->denies('catalog.view')
            || Gate::forUser($actor)->denies($ability)) {
            $status = $actor === null ? 401 : 403;
            $code = $actor === null ? 'UNAUTHENTICATED' : 'FORBIDDEN';
            $message = $actor === null ? 'Authentication required.' : 'This action is unauthorized.';
            if (str_ends_with($request->path(), 'catalog.php')) {
                return (new HttpResponder)->sendError($code, $message, $status);
            }

            return Response::error($code, $message, $status);
        }

        return $next($request);
    }

    private function ability(Request $request): string
    {
        $page = $request->route('catalog_page');
        if ($page !== null) {
            return 'catalog.view';
        }
        if ($request->route('catalog_storage') !== null) {
            return 'catalog.view';
        }
        if (str_ends_with($request->path(), 'catalog.php')) {
            $action = (string) $request->query('action', '');

            return match ($action) {
                'v1.ping', 'v1.publicCatalogSnapshot', 'v1.specSearchRootCategories',
                'v1.specSearchProductCategories', 'v1.specSearchFacets', 'v1.specSearchProducts',
                'v1.downloadMedia' => 'catalog.view',
                'v1.listCsvHistory', 'v1.exportCsv', 'v1.importCsv', 'v1.restoreCsv',
                'v1.downloadCsv', 'v1.deleteCsv' => 'catalog.csv',
                'v1.truncateCatalog' => 'catalog.truncate',
                'v1.createLatexTemplate', 'v1.updateLatexTemplate', 'v1.deleteLatexTemplate',
                'v1.buildLatexTemplate' => 'catalog.templates',
                'v1.saveNode', 'v1.deleteNode', 'v1.setSeriesTypstTemplating', 'v1.saveSeriesField',
                'v1.saveSeriesAttributes', 'v1.deleteSeriesField', 'v1.saveProduct',
                'v1.deleteProduct' => 'catalog.manage',
                default => 'catalog.view',
            };
        }
        $path = $request->path();
        if (str_contains($path, '/spec-search/') || str_contains($path, '/catalog/hierarchy.php')
            || str_contains($path, '/catalog/search.php') || str_contains($path, '/series/details.php')) {
            return 'catalog.view';
        }
        if (str_contains($path, '/csv-')) {
            return 'catalog.csv';
        }
        if (str_ends_with($path, '/truncate.php')) {
            return 'catalog.truncate';
        }
        if (str_ends_with($path, '/pdf.php') || str_ends_with($path, '/compile.php')
            || ! in_array($request->getRealMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return 'catalog.templates';
        }

        return 'catalog.view';
    }
}
