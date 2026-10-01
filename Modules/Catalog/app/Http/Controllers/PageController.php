<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Catalog\Support\Config;

final class PageController
{
    public function show(Request $request): Response
    {
        $page = (string) $request->route('catalog_page');
        abort_unless(in_array($page, [
            'catalog_ui', 'catalog-csv', 'spec-search',
            'latex-templating', 'global_typst_template', 'series_typst_template',
        ], true), 404);
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/public/assets/manifest.json'), true);

        return response()->view('catalog::'.$page, [
            'catalogBaseUrl' => Config::get('app')['base_url'],
            'catalogAssetVersions' => $manifest,
        ]);
    }
}
