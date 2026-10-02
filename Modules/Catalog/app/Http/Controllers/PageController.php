<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Modules\Catalog\Filament\CatalogPage;
use Modules\Catalog\Support\Config;

final class PageController
{
    public function show(Request $request): Response|RedirectResponse
    {
        $page = (string) $request->route('catalog_page');
        $filamentPage = CatalogPage::DOCUMENT_PAGES[$page] ?? null;
        abort_if($filamentPage === null, 404);

        if ($request->header('Sec-Fetch-Dest') !== 'iframe') {
            return redirect($filamentPage::getUrl(Arr::only($request->query(), CatalogPage::QUERY_PARAMETERS), panel: 'admin'));
        }
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/public/assets/manifest.json'), true);

        return response()->view('catalog::'.$page, [
            'catalogBaseUrl' => Config::get('app')['base_url'],
            'catalogAssetVersions' => $manifest,
        ])->header('Cache-Control', 'no-store, private');
    }
}
