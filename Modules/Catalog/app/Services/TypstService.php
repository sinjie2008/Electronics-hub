<?php

declare(strict_types=1);

namespace Modules\Catalog\Services;

use Modules\Catalog\Repositories\TypstRepository;
use Modules\Catalog\Support\CompilerRunner;
use Modules\Catalog\Support\Config;
use Modules\Catalog\Support\Db;
use mysqli;
use RuntimeException;

/**
 * Service for managing Typst templates and global variables.
 */
final class TypstService
{
    private mysqli $db;

    private TypstRepository $typst;

    private string $projectRoot;

    private string $publicRoot;

    private string $buildStorageDir;

    private string $pdfStorageDir;

    private string $pdfUrlPrefix;

    private string $typstBinary;

    private string $assetStorageDir;

    private string $mediaStorageDir;

    /** @var array<string, string> */
    private array $copiedAssets = [];

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Db::connection();
        $config = Config::get('app');
        $this->projectRoot = rtrim(
            (string) ($config['project_root'] ?? dirname(__DIR__, 2)),
            '/\\'
        );
        $this->publicRoot = rtrim(
            (string) ($config['public_root'] ?? $this->projectRoot.'/public'),
            '/\\'
        );
        $storageConfig = (array) ($config['storage'] ?? []);
        $typstConfig = (array) ($config['typst'] ?? []);
        $this->buildStorageDir = rtrim(
            (string) ($storageConfig['typst_build'] ?? $this->projectRoot.'/storage/typst-build'),
            '/\\'
        );
        $this->pdfStorageDir = rtrim(
            (string) ($storageConfig['typst_pdfs'] ?? $this->publicRoot.'/storage/typst-pdfs'),
            '/\\'
        );
        $this->assetStorageDir = rtrim(
            (string) ($storageConfig['typst_assets'] ?? $this->publicRoot.'/storage/typst-assets'),
            '/\\'
        );
        $this->mediaStorageDir = rtrim(
            (string) ($storageConfig['media'] ?? $this->projectRoot.'/storage/media'),
            '/\\'
        );
        $this->pdfUrlPrefix = rtrim((string) ($typstConfig['pdf_url_prefix'] ?? 'storage/typst-pdfs'), '/');
        $this->typstBinary = (string) ($typstConfig['binary'] ?? (
            is_file($this->projectRoot.'/bin/typst.exe')
                ? $this->projectRoot.'/bin/typst.exe'
                : 'typst'
        ));
        $this->typst = new TypstRepository($this->db);

    }

    /**
     * List global templates.
     */
    public function listGlobalTemplates(): array
    {
        return array_map(fn (array $row): array => $this->normalizeTemplateRow($row), $this->typst->listGlobalTemplates());
    }

    /**
     * Get a global template.
     */
    public function getGlobalTemplate(int $id): ?array
    {
        $row = $this->typst->findGlobalTemplate($id);

        return $row === null ? null : $this->normalizeTemplateRow($row);
    }

    /**
     * Create global template.
     */
    public function createGlobalTemplate(string $title, string $description, string $typstCode, ?string $pdfPath = null): array
    {
        $id = $this->typst->insertGlobalTemplate($title, $description, $typstCode, $pdfPath);

        return $this->getGlobalTemplate($id) ?? [];
    }

    /**
     * Update global template.
     */
    public function updateGlobalTemplate(int $id, string $title, string $description, string $typstCode, ?string $pdfPath = null): ?array
    {
        $this->typst->updateGlobalTemplate($id, $title, $description, $typstCode, $pdfPath);

        return $this->getGlobalTemplate($id);
    }

    /**
     * Delete global template.
     */
    public function deleteGlobalTemplate(int $id): bool
    {
        return $this->typst->deleteGlobalTemplate($id);
    }

    /**
     * List series templates.
     */
    public function listSeriesTemplates(int $seriesId): array
    {
        return array_map(fn (array $row): array => $this->normalizeTemplateRow($row), $this->typst->listSeriesTemplates($seriesId));
    }

    /**
     * Get the stored global template preference for a series.
     */
    public function getSeriesPreference(int $seriesId): array
    {
        $row = $this->typst->findSeriesPreference($seriesId);

        $prefId = null;
        if ($row && array_key_exists('last_global_template_id', $row) && $row['last_global_template_id'] !== null) {
            $prefId = (int) $row['last_global_template_id'];
        }

        return [
            'seriesId' => $seriesId,
            'lastGlobalTemplateId' => $prefId,
        ];
    }

    /**
     * Persist the last imported global template selection for a series.
     *
     * @throws RuntimeException when the referenced global template does not exist
     */
    public function saveSeriesPreference(int $seriesId, ?int $lastGlobalTemplateId): array
    {
        if ($seriesId <= 0) {
            throw new RuntimeException('Invalid series id.');
        }

        if ($lastGlobalTemplateId !== null) {
            $template = $this->getGlobalTemplate($lastGlobalTemplateId);
            if ($template === null) {
                throw new RuntimeException('Global template not found.');
            }
        }

        $this->typst->saveSeriesPreference($seriesId, $lastGlobalTemplateId);

        return $this->getSeriesPreference($seriesId);
    }

    /**
     * Get template (global or series).
     */
    public function getTemplate(int $id): ?array
    {
        $row = $this->typst->findTemplate($id);

        return $row === null ? null : $this->normalizeTemplateRow($row);
    }

    /**
     * Create series template.
     */
    public function createSeriesTemplate(int $seriesId, string $title, string $description, string $typstCode, ?string $pdfPath = null): array
    {
        $id = $this->typst->insertSeriesTemplate($seriesId, $title, $description, $typstCode, $pdfPath);

        return $this->getTemplate($id) ?? [];
    }

    /**
     * Update series template.
     */
    public function updateSeriesTemplate(int $id, int $seriesId, string $title, string $description, string $typstCode, ?string $pdfPath = null): ?array
    {
        $this->typst->updateSeriesTemplate($id, $seriesId, $title, $description, $typstCode, $pdfPath);

        return $this->getTemplate($id);
    }

    /**
     * Compile Typst code.
     *
     * @param  string  $typstCode  The user's Typst code.
     * @param  int|null  $seriesId  If provided, injects series data.
     */
    public function compileTypst(string $typstCode, ?int $seriesId = null): array
    {
        CompilerRunner::assertModuleEnabled();
        CompilerRunner::assertSourceSize($typstCode);
        $buildDir = CompilerRunner::createJobDirectory($this->buildStorageDir);
        $this->copiedAssets = [];
        try {
            $dataHeaderResult = $this->generateDataHeader($seriesId, $buildDir);
            $placeholderValues = $this->buildPlaceholderMap($dataHeaderResult['safeData']);
            $renderedTypst = $this->replacePlaceholders($typstCode, $placeholderValues);
            $fullSource = $dataHeaderResult['header']."\n".$renderedTypst;
            CompilerRunner::assertSourceSize($fullSource);
            $fullSource = $dataHeaderResult['header']."\n".$this->stageInlineAssets($renderedTypst, $buildDir);
            $jobId = uniqid('typst_');
            if (file_put_contents($buildDir.'/'.$jobId.'.typ', $fullSource) === false) {
                throw new RuntimeException('Unable to write document source.');
            }
            $pdfFile = $buildDir.'/'.$jobId.'.pdf';
            $result = (new CompilerRunner)->run($this->typstBinary, [
                'compile', '--root', '/work', $jobId.'.typ', $jobId.'.pdf',
            ], $buildDir);
            if ($result['exitCode'] !== 0 || ! is_file($pdfFile) || is_link($pdfFile)) {
                throw new RuntimeException("Typst Compilation failed:\n".$result['stdout'].$result['stderr']);
            }
            if (! is_dir($this->pdfStorageDir) && ! mkdir($this->pdfStorageDir, 0775, true) && ! is_dir($this->pdfStorageDir)) {
                throw new RuntimeException('Unable to create PDF storage.');
            }
            $finalPdfName = ($seriesId ? 'series_'.$seriesId : 'global').'_'.date('YmdHis').'_'.bin2hex(random_bytes(8)).'.pdf';
            $finalPdfPath = str_replace('\\', '/', (realpath($this->pdfStorageDir) ?: $this->pdfStorageDir).'/'.$finalPdfName);
            if (! rename($pdfFile, $finalPdfPath)) {
                throw new RuntimeException('Unable to store compiled PDF.');
            }

            return [
                'url' => $this->pdfUrlPrefix.'/'.$finalPdfName,
                'path' => $finalPdfPath,
            ];
        } finally {
            CompilerRunner::cleanup($buildDir);
            $this->copiedAssets = [];
        }
    }

    /**
     * Build the Typst data header and return sanitized data for placeholder mapping.
     *
     * @param  string  $buildDir  Directory where assets should be staged for Typst.
     * @return array{header: string, safeData: array<string, mixed>}
     */
    private function generateDataHeader(?int $seriesId, string $buildDir): array
    {
        $header = "// Auto-generated Data Header\n";

        // Global Variables
        $globalVars = $this->listGlobalVariables();
        // Construct a `data` dictionary
        $data = ['globals' => []];
        foreach ($globalVars as $var) {
            $data['globals'][$var['key']] = $this->resolveAssetPath($var['value'], $buildDir);
        }

        if ($seriesId) {
            $catalogService = new CatalogService($this->db);
            $seriesDetails = $catalogService->getSeriesDetails($seriesId);

            if ($seriesDetails) {
                $data['series'] = [
                    'name' => $seriesDetails['name'] ?? '',
                    'id' => $seriesDetails['id'] ?? '',
                    // Flatten metadata
                ];

                $data['metadata'] = [];
                foreach ($seriesDetails['metadata'] ?? [] as $m) {
                    $data['metadata'][$m['key']] = $this->resolveAssetPath($m['value'], $buildDir, true);
                }

                $data['products'] = [];

                foreach ($this->typst->getSeriesProducts($seriesId) as $product) {
                    $pData = [
                        'name' => $product['name'],
                        'sku' => $product['sku'],
                        'attributes' => [],
                    ];

                    foreach ($this->typst->getProductAttributes((int) $product['id']) as $attr) {
                        $resolved = $this->resolveAssetPath($attr['value'] ?? '', $buildDir, true);
                        $pData['attributes'][$attr['field_key']] = $resolved;
                        // Also put at top level of product for easy access
                        $pData[$attr['field_key']] = $resolved;
                    }

                    $data['products'][] = $pData;
                }
            }
        }

        // Convert $data to Typst syntax
        // #let data = ( ... )
        $safeData = $this->sanitizeTypstStructure($data);
        $header .= '#let data = '.$this->toTypstValue($safeData)."\n";

        // Helper aliases for easier access
        $header .= "#let globals = data.globals\n";
        if ($seriesId) {
            $header .= "#let series = data.series\n";
            $header .= "#let metadata = data.metadata\n";
            $header .= "#let products = data.products\n";
        }

        return [
            'header' => $header,
            'safeData' => $safeData,
        ];
    }

    /**
     * Flatten key/value placeholders ({{key}}) for easy Typst authoring.
     *
     * @param  array<string, mixed>  $safeData
     * @return array<string, string>
     */
    private function buildPlaceholderMap(array $safeData): array
    {
        $map = [];

        foreach ($safeData['globals'] ?? [] as $key => $value) {
            $map[$key] = $this->stringifyPlaceholderValue($value);
        }

        foreach ($safeData['metadata'] ?? [] as $key => $value) {
            $map[$key] = $this->stringifyPlaceholderValue($value);
        }

        // Use first product's attributes for quick access (series page is product-centric).
        $firstProduct = $safeData['products'][0] ?? null;
        if (is_array($firstProduct)) {
            foreach (($firstProduct['attributes'] ?? []) as $key => $value) {
                $map[$key] = $this->stringifyPlaceholderValue($value);
            }
        }

        return $map;
    }

    /**
     * Stage assets referenced directly in Typst code (e.g., #image("path")) into the build directory.
     */
    private function stageInlineAssets(string $typstCode, string $buildDir): string
    {
        return (string) preg_replace_callback('/(#image\\s*\\(\\s*")([^"]+)(")/', function (array $matches) use ($buildDir): string {
            $path = $this->resolveAssetPath($matches[2], $buildDir, true);

            return $matches[1].$path.$matches[3];
        }, $typstCode);
    }

    /**
     * Replace {{key}} placeholders with concrete values prior to Typst compilation.
     *
     * @param  array<string, string>  $values
     */
    private function replacePlaceholders(string $typstCode, array $values): string
    {
        return (string) preg_replace_callback('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', function ($matches) use ($values) {
            $key = $matches[1];
            if (! array_key_exists($key, $values)) {
                return $matches[0];
            }

            return $values[$key];
        }, $typstCode);
    }

    /**
     * Normalize placeholder values to strings.
     *
     * @param  mixed  $value
     */
    private function stringifyPlaceholderValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    /**
     * Convert a PHP value into Typst literal syntax.
     *
     * @param  mixed  $value
     */
    private function toTypstValue($value)
    {
        if (is_array($value)) {
            // Check if associative (dictionary) or sequential (array)
            $isAssoc = array_keys($value) !== range(0, count($value) - 1);
            if (empty($value)) {
                return '()';
            }

            $items = [];
            if ($isAssoc) {
                foreach ($value as $k => $v) {
                    $items[] = $k.': '.$this->toTypstValue($v);
                }

                return '('.implode(', ', $items).')';
            } else {
                foreach ($value as $v) {
                    $items[] = $this->toTypstValue($v);
                }

                return '('.implode(', ', $items).')';
            }
        } elseif (is_string($value)) {
            // Escape string
            return '"'.str_replace(['\\', '"'], ['\\\\', '\"'], $value).'"';
        } elseif (is_bool($value)) {
            return $value ? 'true' : 'false';
        } elseif (is_numeric($value)) {
            return $value;
        } elseif (is_null($value)) {
            return 'none';
        }

        return '""';
    }

    /**
     * Recursively sanitize associative keys to Typst-safe identifiers.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private function sanitizeTypstStructure($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        $isAssoc = array_keys($value) !== range(0, count($value) - 1);
        if (! $isAssoc) {
            return array_map([$this, 'sanitizeTypstStructure'], $value);
        }

        $result = [];
        $usedKeys = [];
        foreach ($value as $key => $item) {
            $baseKey = $this->sanitizeTypstKey((string) $key);
            $safeKey = $this->ensureUniqueTypstKey($baseKey, $usedKeys);
            $usedKeys[$safeKey] = true; // track to avoid collisions
            $result[$safeKey] = $this->sanitizeTypstStructure($item);
        }

        return $result;
    }

    /**
     * Normalize a string into a Typst-safe identifier.
     */
    private function sanitizeTypstKey(string $key): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9_]/', '_', $key);
        $normalized = ltrim((string) $normalized, '_');
        if ($normalized === '') {
            $normalized = 'key';
        }
        if (preg_match('/^[0-9]/', $normalized) === 1) {
            $normalized = '_'.$normalized;
        }

        return $normalized;
    }

    /**
     * Ensure sanitized keys stay unique within the same dictionary by suffixing when needed.
     *
     * @param  array<string, bool>  $usedKeys
     */
    private function ensureUniqueTypstKey(string $baseKey, array $usedKeys): string
    {
        $candidate = $baseKey;
        $suffix = 1;
        while (isset($usedKeys[$candidate])) {
            $candidate = $baseKey.'_'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Resolve and stage asset paths (e.g., images) into the Typst build directory, returning a path Typst can load.
     *
     * @param  mixed  $value
     */
    private function resolveAssetPath($value, string $buildDir, bool $createPlaceholder = false)
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        $location = $this->storageAssetLocation($value);
        if ($location === null) {
            return $value;
        }
        $sourcePath = $location['path'];
        $relativePath = $location['relative'];
        if (isset($this->copiedAssets[$sourcePath])) {
            return $this->copiedAssets[$sourcePath];
        }
        if (count($this->copiedAssets) >= 64 || filesize($sourcePath) > 16777216) {
            throw new RuntimeException('Document assets exceed the compilation limit.');
        }
        $totalBytes = filesize($sourcePath);
        foreach (array_keys($this->copiedAssets) as $copiedSource) {
            $totalBytes += filesize($copiedSource);
        }
        if ($totalBytes > 33554432) {
            throw new RuntimeException('Document assets exceed the compilation limit.');
        }
        $destination = $buildDir.'/'.$relativePath;
        $destinationDirectory = dirname($destination);
        if (! is_dir($destinationDirectory) && ! mkdir($destinationDirectory, 0700, true) && ! is_dir($destinationDirectory)) {
            throw new RuntimeException('Unable to stage document asset.');
        }
        if (! copy($sourcePath, $destination)) {
            throw new RuntimeException('Unable to stage document asset.');
        }
        $this->copiedAssets[$sourcePath] = $relativePath;

        return $relativePath;
    }

    /**
     * Persist an uploaded image into the public Typst assets directory and return its relative path.
     *
     * @param  array<string, mixed>  $fileUpload
     */
    private function storeUploadedAsset(array $fileUpload): string
    {
        $error = (int) ($fileUpload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('File upload failed.');
        }

        $tmpPath = (string) ($fileUpload['tmp_name'] ?? '');
        if ($tmpPath === '' || ! is_uploaded_file($tmpPath)) {
            throw new RuntimeException('Invalid upload payload.');
        }

        $imageInfo = @getimagesize($tmpPath);
        if ($imageInfo === false) {
            throw new RuntimeException('Uploaded file is not a valid image.');
        }

        $original = (string) ($fileUpload['name'] ?? 'upload');
        $base = preg_replace('/[^A-Za-z0-9_-]/', '_', pathinfo($original, PATHINFO_FILENAME)) ?: 'asset';
        $extension = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));
        $extension = $extension !== '' ? '.'.$extension : '';
        $unique = str_replace('.', '_', uniqid('typst_', true));
        $filename = $base.'_'.$unique.$extension;

        if (! is_dir($this->assetStorageDir) && ! mkdir($this->assetStorageDir, 0777, true) && ! is_dir($this->assetStorageDir)) {
            throw new RuntimeException('Unable to create asset storage directory.');
        }

        $destination = $this->assetStorageDir.'/'.$filename;
        if (! move_uploaded_file($tmpPath, $destination)) {
            throw new RuntimeException('Failed to store uploaded asset.');
        }

        return 'typst-assets/'.$filename;
    }

    // Variable CRUD methods...
    public function listGlobalVariables(): array
    {
        return array_map(fn (array $row): array => $this->normalizeVariableRow($row), $this->typst->listGlobalVariables());
    }

    /**
     * List variables scoped to a specific category/series (`is_global = 0`).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listScopedVariables(int $seriesId): array
    {
        return array_map(fn (array $row): array => $this->normalizeVariableRow($row), $this->typst->listScopedVariables($seriesId));
    }

    /**
     * Create or update a global variable and optionally persist an uploaded image asset.
     *
     * @param  array<string, mixed>|null  $fileUpload
     */
    public function saveGlobalVariable(string $key, string $inputType, string $value, ?int $id = null, ?array $fileUpload = null): ?array
    {
        return $this->persistVariable($key, $inputType, $value, $id, true, null, $fileUpload);
    }

    /**
     * Create or update a category/series-scoped variable (`is_global = 0`).
     *
     * @param  array<string, mixed>|null  $fileUpload
     */
    public function saveScopedVariable(
        int $seriesId,
        string $key,
        string $inputType,
        string $value,
        ?int $id = null,
        ?array $fileUpload = null
    ): ?array {
        if ($seriesId <= 0) {
            throw new RuntimeException('Series ID is required for scoped variables.');
        }

        return $this->persistVariable($key, $inputType, $value, $id, false, $seriesId, $fileUpload);
    }

    public function deleteGlobalVariable(int $id): bool
    {
        return $this->typst->deleteGlobalVariable($id);
    }

    /**
     * Delete a scoped variable tied to a category/series.
     */
    public function deleteScopedVariable(int $id, int $seriesId): bool
    {
        return $this->typst->deleteScopedVariable($id, $seriesId);
    }

    public function getGlobalVariable(int $id): ?array
    {
        return $this->getVariable($id, true, null);
    }

    /**
     * Fetch a scoped variable belonging to a category/series.
     */
    public function getScopedVariable(int $id, int $seriesId): ?array
    {
        return $this->getVariable($id, false, $seriesId);
    }

    private function normalizeTemplateRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'description' => (string) ($row['description'] ?? ''),
            'typst' => (string) ($row['typst_content'] ?? ''),
            'isGlobal' => (bool) $row['is_global'],
            'seriesId' => isset($row['series_id']) ? (int) $row['series_id'] : null,
            'lastPdfPath' => $row['last_pdf_path'] ?? null,
            'lastPdfGeneratedAt' => $row['last_pdf_generated_at'] ?? null,
            'downloadUrl' => $this->buildPdfUrl($row['last_pdf_path'] ?? null),
            'createdAt' => $row['created_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
        ];
    }

    private function normalizeVariableRow(array $row): array
    {
        $preview = $this->buildAssetPreview((string) ($row['field_value'] ?? ''));

        return [
            'id' => (int) $row['id'],
            'key' => (string) $row['field_key'],
            'type' => (string) $row['field_type'],
            'value' => $row['field_value'] ?? '',
            'previewUrl' => $preview['url'],
            'previewDataUrl' => $preview['dataUri'],
            'isGlobal' => (bool) $row['is_global'],
            'seriesId' => isset($row['series_id']) ? (int) $row['series_id'] : null,
            'createdAt' => $row['created_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
        ];
    }

    private function normalizeVariableType(string $inputType): string
    {
        $trimmed = strtolower(trim($inputType));
        if ($trimmed === 'file' || $trimmed === 'image') {
            return 'image';
        }

        return 'text';
    }

    /**
     * Persist a variable (global or scoped) and return the stored row.
     *
     * @param  array<string, mixed>|null  $fileUpload
     */
    private function persistVariable(
        string $key,
        string $inputType,
        string $value,
        ?int $id,
        bool $isGlobal,
        ?int $seriesId,
        ?array $fileUpload = null
    ): ?array {
        $key = trim($key);
        $normalizedType = $this->normalizeVariableType($inputType);
        $valueToStore = $value;

        if ($normalizedType === 'image' && $fileUpload !== null && ($fileUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ((int) ($fileUpload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('File upload failed.');
            }
            $valueToStore = $this->storeUploadedAsset($fileUpload);
        }

        $row = $this->typst->saveVariable($key, $normalizedType, $valueToStore, $id, $isGlobal, $seriesId);

        return $row === null ? null : $this->normalizeVariableRow($row);
    }

    /**
     * Fetch a variable row by id and scope.
     */
    private function getVariable(int $id, bool $isGlobal, ?int $seriesId = null): ?array
    {
        $row = $this->typst->findVariable($id, $isGlobal, $seriesId);

        return $row === null ? null : $this->normalizeVariableRow($row);
    }

    private function buildPdfUrl(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        // If path contains 'public', strip everything before it.
        // Simply use the prefix + basename, assuming all PDFs are in the configured directory.
        return $this->pdfUrlPrefix.'/'.basename($path);
    }

    /**
     * Attempt to convert a stored file/image path into a web-accessible URL or data URI for previews.
     *
     * @return array{url: string|null, dataUri: string|null}
     */
    private function buildAssetPreview(string $value): array
    {
        if (preg_match('~^https?://~i', $value) === 1) {
            return ['url' => $value, 'dataUri' => null];
        }
        $location = $this->storageAssetLocation($value);

        return ['url' => $location['url'] ?? null, 'dataUri' => null];
    }

    /**
     * Resolve image references only inside approved asset roots. Each path component
     * must stay within its root, including symlinks; source and staging paths are distinct.
     *
     * @return array{path: string, url: string, relative: string}|null
     */
    private function storageAssetLocation(string $value): ?array
    {
        if ($value === '' || strlen($value) > 1024 || str_contains($value, "\0") || str_contains($value, '\\')) {
            return null;
        }
        $baseUrl = rtrim((string) (Config::get('app')['base_url'] ?? ''), '/');
        foreach ([
            ['typst-assets', $this->assetStorageDir, ['typst-assets/', 'storage/typst-assets/']],
            ['media', $this->mediaStorageDir, ['media/', 'storage/media/']],
            ['assets', $this->publicRoot.'/assets', ['assets/']],
        ] as [$kind, $directory, $prefixes]) {
            $urlPrefix = ($baseUrl === '' ? '' : $baseUrl.'/').($kind === 'assets' ? 'assets/' : 'storage/'.$kind.'/');
            $relative = $value;
            foreach ([$urlPrefix, ...$prefixes] as $prefix) {
                if (str_starts_with($value, $prefix)) {
                    $relative = substr($value, strlen($prefix));
                    break;
                }
            }
            if (str_starts_with($relative, '/') || str_contains($relative, ':')
                || preg_match('~(?:^|/)(?:\.\.?|)(?:/|$)~', $relative) === 1
                || preg_match('~\.(?:png|jpe?g|gif|webp|svg|pdf)$~i', $relative) !== 1) {
                continue;
            }
            $root = realpath($directory);
            $path = realpath($directory.'/'.$relative);
            if ($root === false || $path === false || ! is_file($path) || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
                continue;
            }
            $componentPath = $root;
            $hasSymlink = false;
            foreach (explode('/', $relative) as $component) {
                $componentPath .= '/'.$component;
                if (is_link($componentPath)) {
                    $hasSymlink = true;
                    break;
                }
            }
            if ($hasSymlink) {
                continue;
            }
            $stagedRelative = str_starts_with($value, '/') || str_contains($value, ':') ? $kind.'/'.$relative : $value;

            return ['path' => $path, 'url' => $urlPrefix.$relative, 'relative' => $stagedRelative];
        }

        return null;
    }

    /**
     * Delete template by id (global or series).
     */
    public function deleteTemplate(int $id): bool
    {
        return $this->typst->deleteTemplate($id);
    }
}
