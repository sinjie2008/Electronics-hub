<?php

declare(strict_types=1);

namespace Modules\Catalog\Services;

use Modules\Catalog\Http\CatalogApiException;
use Modules\Catalog\Support\CompilerRunner;
use Modules\Catalog\Support\Config;
use RuntimeException;

final class LatexBuildService
{
    private string $pdflatexPath;

    private string $storageDir;

    private string $workingDir;

    private string $relativePrefix;

    public function __construct(
        string $pdflatexPath,
        ?string $storageDir = null,
        ?string $workingDir = null
    ) {
        $this->pdflatexPath = $pdflatexPath !== '' ? $pdflatexPath : Config::get('app')['latex']['default_binary'];
        $this->storageDir = rtrim($storageDir ?? Config::get('app')['storage']['latex_pdfs'], DIRECTORY_SEPARATOR);
        $this->workingDir = rtrim($workingDir ?? Config::get('app')['storage']['latex_build'], DIRECTORY_SEPARATOR);
        $this->relativePrefix = ltrim(Config::get('app')['latex']['pdf_url_prefix'], '/');

        $this->ensureDirectory($this->storageDir);
        $this->ensureDirectory($this->workingDir);
    }

    /**
     * Compiles LaTeX into a PDF using MiKTeX.
     *
     * @return array<string, mixed>
     */
    public function build(int $templateId, string $latex): array
    {
        CompilerRunner::assertModuleEnabled();
        CompilerRunner::assertSourceSize($latex);
        $timestamp = (new \DateTimeImmutable('now'))->format('YmdHis');
        $token = bin2hex(random_bytes(4));
        $baseName = sprintf('template_%d_%s_%s', $templateId, $timestamp, $token);
        $jobDirectory = CompilerRunner::createJobDirectory($this->workingDir);
        $correlationId = bin2hex(random_bytes(16));
        try {
            if (file_put_contents($jobDirectory.'/'.$baseName.'.tex', $latex) === false) {
                throw new CatalogApiException('LATEX_BUILD_ERROR', 'Unable to write temporary LaTeX source file.', 500);
            }
            $result = (new CompilerRunner)->run($this->pdflatexPath, [
                '-no-shell-escape', '-interaction=nonstopmode', '-halt-on-error',
                '-output-directory=.', $baseName.'.tex',
            ], $jobDirectory);
            $stdout = $result['stdout'];
            $stderr = $result['stderr'];
            $exitCode = $result['exitCode'];
            $pdfSource = $jobDirectory.'/'.$baseName.'.pdf';
            if ($exitCode !== 0 || ! is_file($pdfSource) || is_link($pdfSource)) {
                throw new CatalogApiException('LATEX_BUILD_ERROR', 'MiKTeX compilation failed.', 500, [
                    'stdout' => trim($stdout),
                    'stderr' => trim($stderr),
                    'exitCode' => $exitCode,
                    'correlationId' => $correlationId,
                ]);
            }
            $pdfFilename = sprintf('%d-%s-%s.pdf', $templateId, $timestamp, $token);
            $targetPath = $this->storageDir.DIRECTORY_SEPARATOR.$pdfFilename;
            $this->movePdf($pdfSource, $targetPath);

            return [
                'relativePath' => $this->relativePrefix.'/'.$pdfFilename,
                'absolutePath' => $targetPath,
                'stdout' => trim($stdout),
                'stderr' => trim($stderr),
                'exitCode' => $exitCode,
                'log' => trim($stdout.PHP_EOL.$stderr),
                'correlationId' => $correlationId,
            ];
        } finally {
            CompilerRunner::cleanup($jobDirectory);
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            if (! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                throw new RuntimeException('Unable to create directory: '.$directory);
            }
        }
    }

    private function movePdf(string $source, string $destination): void
    {
        if (@rename($source, $destination)) {
            return;
        }

        if (! @copy($source, $destination)) {
            throw new CatalogApiException(
                'LATEX_BUILD_ERROR',
                'Unable to move generated PDF into storage.',
                500
            );
        }
        @unlink($source);
    }
}
