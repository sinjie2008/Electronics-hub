<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Controllers;

use Illuminate\Http\Request;
use Modules\Catalog\Support\Config;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/** Serve only declared module assets and public file categories. */
final class StorageController
{
    public function download(Request $request, string $path): Response
    {
        $kind = (string) $request->route('catalog_storage');
        $settings = Config::get('app');
        $directory = match ($kind) {
            'assets' => dirname(__DIR__, 3).'/public/assets',
            'media' => $settings['storage']['media'],
            'latex-pdfs' => $settings['storage']['latex_pdfs'],
            'typst-pdfs' => $settings['storage']['typst_pdfs'],
            'typst-assets' => $settings['storage']['typst_assets'],
            default => null,
        };
        if ($directory === null || str_contains($path, "\0")) {
            return new Response('', 404);
        }
        $root = realpath($directory);
        $file = realpath($directory.DIRECTORY_SEPARATOR.$path);
        if ($root === false || $file === false || ! is_file($file) || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR)) {
            return new Response('', 404);
        }
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $contentType = $kind === 'assets' ? match ($extension) {
            'js' => 'text/javascript; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            default => 'application/octet-stream',
        } : (mime_content_type($file) ?: match ($extension) {
            'pdf' => 'application/pdf', 'glb' => 'model/gltf-binary', default => 'application/octet-stream',
        });
        $version = $kind === 'assets' ? $request->query('v') : null;
        $versioned = is_string($version)
            && hash_equals(substr(hash_file('sha256', $file), 0, 16), $version);

        return new BinaryFileResponse($file, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => $versioned ? 'public, max-age=31536000, immutable' : 'no-cache, private',
        ], public: $versioned);
    }
}
