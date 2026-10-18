<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Catalog\Http\CorrelationId;
use Modules\Catalog\Http\HttpResponder;
use Symfony\Component\HttpFoundation\Response;

/** Every legacy mount shares this fail-closed permission boundary. */
final class CatalogAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->headers->has('Authorization');
        $actor = $bearer && $request->bearerToken() === null ? null : $request->user($bearer ? 'api' : 'web');
        $out = new HttpResponder;
        $out->setCorrelationId(CorrelationId::fromRequest($request));
        if (! $actor instanceof User) {
            return $out->sendError('UNAUTHENTICATED', 'Authentication is required.', 401);
        }
        if (! $actor->is_active || ! Gate::forUser($actor)->allows('catalog.view')) {
            return $out->sendError('FORBIDDEN', 'Catalog access is not permitted.', 403);
        }
        $endpoint = (string) $request->route('catalog_endpoint', '');
        if (preg_match('~/(?:csv-(?:export|import|restore)|truncate|pdf)\\.php$~', $endpoint)
            && $request->getRealMethod() !== 'POST') {
            $response = $out->sendError('METHOD_NOT_ALLOWED', 'This operation requires POST.', 405);
            $response->headers->set('Allow', 'POST');

            return $response;
        }
        foreach ($this->operations($request) as $operation) {
            if (! Gate::forUser($actor)->allows('catalog.'.$operation)) {
                return $out->sendError('FORBIDDEN', 'The Catalog operation is not permitted.', 403);
            }
        }
        $request->attributes->set('catalog.bearer', $bearer);
        $request->setUserResolver(fn () => $actor);

        return $next($request);
    }

    /** @return list<string> */
    private function operations(Request $request): array
    {
        $endpoint = (string) $request->route('catalog_endpoint', '');
        if ($endpoint === 'catalog.php') {
            $action = $request->query->all()['action'] ?? '';

            return match ($action) {
                'v1.saveNode', 'v1.saveSeriesField' => [$this->saveOperation($request)],
                'v1.saveProduct' => [$this->saveOperation($request, 'metadata')],
                'v1.createLatexTemplate' => ['create'],
                'v1.updateLatexTemplate', 'v1.setSeriesTypstTemplating', 'v1.saveSeriesAttributes', 'v1.buildLatexTemplate' => ['update'],
                'v1.deleteNode', 'v1.deleteProduct', 'v1.deleteSeriesField', 'v1.deleteLatexTemplate', 'v1.deleteCsv' => ['delete'],
                'v1.importCsv', 'v1.restoreCsv' => ['create', 'update', 'delete'],
                'v1.truncateCatalog' => ['delete'],
                'v1.exportCsv' => ['update'],
                'v1.ping', 'v1.listHierarchy', 'v1.listSeriesFields', 'v1.publicCatalogSnapshot',
                'v1.specSearchRootCategories', 'v1.specSearchProductCategories', 'v1.specSearchFacets', 'v1.specSearchProducts',
                'v1.listLatexTemplates', 'v1.getLatexTemplate', 'v1.getSeriesAttributes', 'v1.listProducts',
                'v1.listCsvHistory', 'v1.downloadCsv', 'v1.downloadMedia' => [],
                // An unrecognized action never acquires a mutation permission by default.
                default => ['create', 'update', 'delete'],
            };
        }
        if (str_contains($endpoint, 'csv-import') || str_contains($endpoint, 'csv-restore')) {
            return ['create', 'update', 'delete'];
        }
        if (str_contains($endpoint, 'truncate')) {
            return ['delete'];
        }
        if (str_contains($endpoint, 'csv-export') || str_contains($endpoint, 'compile') || str_ends_with($endpoint, '/pdf.php')) {
            return ['update'];
        }
        if (str_contains($endpoint, '/typst/') || str_contains($endpoint, '/latex/')) {
            return match ($request->getRealMethod()) {
                'POST' => str_ends_with($endpoint, '/templates.php') ? ['create']
                    : [$this->saveOperation($request, str_contains($endpoint, '/typst/') && $request->route()->getActionMethod() === 'variables' ? 'fields' : null)],
                'PUT', 'PATCH' => ['update'],
                'DELETE' => ['delete'],
                default => [],
            };
        }

        return [];
    }

    private function saveOperation(Request $request, ?string $multipart = null): string
    {
        // Match the domain reader, which accepts JSON regardless of Content-Type.
        $payload = json_decode($request->getContent(), true);
        $isMultipart = stripos((string) $request->header('Content-Type'), 'multipart/form-data') !== false
            || ($multipart === 'fields' && $request->files->all() !== []);
        if ($multipart !== null && $isMultipart) {
            $payload = $request->request->all();
            if ($multipart === 'metadata') {
                $metadata = $payload['metadata'] ?? '';
                $payload = is_string($metadata) ? json_decode($metadata, true) : [];
            }
        }

        // The domain's save methods interpret positive IDs as updates.
        return is_array($payload) && (int) ($payload['id'] ?? 0) > 0 ? 'update' : 'create';
    }
}
