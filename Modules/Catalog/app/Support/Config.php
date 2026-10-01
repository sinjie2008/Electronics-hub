<?php

declare(strict_types=1);

namespace Modules\Catalog\Support;

/** Resolve host settings without static configuration or request state. */
final class Config
{
    /** Named maps merge recursively; explicitly supplied lists replace defaults. */
    public static function combine(array $defaults, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key])
                && ! array_is_list($value) && ! array_is_list($defaults[$key])) {
                $defaults[$key] = self::combine($defaults[$key], $value);
            } else {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
    }

    public static function get(string $name): array
    {
        if ($name === 'db') {
            return app('db')->connection(config('catalog.connection'))->getConfig();
        }
        if ($name !== 'app') {
            throw new \RuntimeException('Unknown Catalog settings: '.$name);
        }
        $settings = (array) config('catalog.settings', []);
        $moduleRoot = dirname(__DIR__, 2);
        $storageRoot = config('catalog.storage_root') ?: storage_path('app/catalog');
        $mount = trim((string) config('catalog.prefix', 'catalog'), '/');
        if (app()->bound('request')) {
            $route = app('request')->route();
            if ($route !== null && array_key_exists('catalog_mount', $route->defaults)) {
                $mount = (string) $route->defaults['catalog_mount'];
            }
        }
        $applicationPath = rtrim((string) (parse_url((string) config('app.url'), PHP_URL_PATH) ?: ''), '/');
        $baseUrl = config('catalog.base_url');
        $baseUrl = $baseUrl !== null ? rtrim((string) $baseUrl, '/') : $applicationPath.($mount === '' ? '' : '/'.$mount);
        $settings['project_root'] = $moduleRoot;
        $settings['public_root'] = public_path($mount);
        $settings['base_url'] = $baseUrl;
        foreach ([
            'csv' => 'csv', 'media' => 'media', 'latex_build' => 'latex-build', 'latex_pdfs' => 'latex-pdfs',
            'api_latex_build' => 'latex-build', 'api_latex_pdfs' => 'latex-pdfs',
            'typst_build' => 'typst-build', 'typst_pdfs' => 'typst-pdfs', 'typst_assets' => 'typst-assets',
        ] as $key => $directory) {
            $settings['storage'][$key] ??= $storageRoot.'/'.$directory;
        }
        $settings['storage']['media_url_prefix'] ??= $baseUrl.'/storage/media';
        $settings['media']['download_url'] ??= ($baseUrl === '' ? '' : $baseUrl.'/').'catalog.php';
        $settings['latex']['pdf_url_prefix'] ??= $baseUrl.'/storage/latex-pdfs';
        $settings['typst']['pdf_url_prefix'] ??= ($baseUrl === '' ? '' : $baseUrl.'/').'storage/typst-pdfs';
        $settings['logging']['path'] ??= storage_path('logs/catalog.log');
        $settings['truncate']['audit_log'] ??= $storageRoot.'/csv/truncate_audit.jsonl';

        return $settings;
    }
}
