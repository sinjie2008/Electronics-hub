<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Modules\Catalog\Filament\CatalogPage;

final class PageController
{
    public function show(Request $request): RedirectResponse
    {
        $page = (string) $request->route('catalog_page');
        $filamentPage = CatalogPage::DOCUMENT_PAGES[$page] ?? null;
        abort_if($filamentPage === null, 404);

        return redirect($filamentPage::getUrl(
            array_filter(Arr::only($request->query(), CatalogPage::QUERY_PARAMETERS), is_scalar(...)),
            panel: 'admin',
        ))->header('Cache-Control', 'no-store, private');
    }
}
