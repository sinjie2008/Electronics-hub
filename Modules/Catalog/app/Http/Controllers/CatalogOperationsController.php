<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Controllers;

use Closure;
use Modules\Catalog\Http\CatalogApiException;
use Modules\Catalog\Http\HttpResponder;
use Modules\Catalog\Http\RequestInput;
use Modules\Catalog\Http\Response;
use Modules\Catalog\Support\Logger;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Handles catalog CSV, PDF, and truncate APIs backed by the legacy catalog application.
 */
final class CatalogOperationsController
{
    /** @var (Closure(): CatalogController)|null */
    private ?Closure $catalogApplicationFactory;

    /**
     * Accept a host-provided application factory; the default stays lazy until
     * an operation reaches its existing application-creation point.
     *
     * @param  (callable(): CatalogController)|null  $catalogApplicationFactory
     */
    public function __construct(private RequestInput $input, ?callable $catalogApplicationFactory = null)
    {
        $this->catalogApplicationFactory = $catalogApplicationFactory === null
            ? null
            : Closure::fromCallable($catalogApplicationFactory);
    }

    /**
     * Stream a saved catalog CSV file.
     */
    public function csvDownload(): HttpResponse
    {
        return $this->run('catalog.csv.download', function (string $correlationId): array {
            $fileId = ($this->input->query('id') !== null) ? (string) $this->input->query('id') : '';
            if ($fileId === '') {
                throw new CatalogApiException('CSV_NOT_FOUND', 'CSV id is required.', 404);
            }
            $app = $this->createCatalogApplication();
            $responder = new HttpResponder;
            $responder->setCorrelationId($correlationId);

            return ['response' => $app->getCsvService()->streamFile($fileId, $responder), 'context' => ['fileId' => $fileId]];
        }, 'CSV_DOWNLOAD_ERROR', null);
    }

    /**
     * Export the catalog as CSV.
     */
    public function csvExport(): HttpResponse
    {
        return $this->run('catalog.csv.export', function (): array {
            $app = $this->createCatalogApplication();

            return ['data' => $app->getCsvService()->exportCatalog()];
        });
    }

    /**
     * List saved CSV import/export history.
     */
    public function csvHistory(): HttpResponse
    {
        return $this->run('catalog.csv.history', function (): array {
            $app = $this->createCatalogApplication();

            return ['data' => $app->getCsvService()->listHistory()];
        });
    }

    /**
     * Import an uploaded CSV file.
     */
    public function csvImport(): HttpResponse
    {
        return $this->run('catalog.csv.import', function (): array {
            if ($this->input->upload('file') === null) {
                throw new CatalogApiException('CSV_REQUIRED', 'CSV file upload is required.', 400);
            }
            $app = $this->createCatalogApplication();

            return [
                'data' => $app->getCsvService()->importFromUploadedFile($this->input->upload('file')),
                'status' => 202,
            ];
        });
    }

    /**
     * Restore catalog data from a saved CSV file.
     */
    public function csvRestore(): HttpResponse
    {
        return $this->run('catalog.csv.restore', function (): array {
            $payload = $this->input->json();
            $fileId = isset($payload['id']) ? (string) $payload['id'] : '';
            if ($fileId === '') {
                throw new CatalogApiException('CSV_NOT_FOUND', 'CSV id is required.', 404);
            }
            $app = $this->createCatalogApplication();

            return [
                'data' => $app->getCsvService()->restoreCatalog($fileId),
                'context' => ['fileId' => $fileId],
            ];
        });
    }

    /**
     * Build and record the PDF for a saved LaTeX template.
     */
    public function pdf(): HttpResponse
    {
        return $this->run('catalog.pdf', function (): array {
            $templateId = ($this->input->query('id') !== null) ? (int) $this->input->query('id') : 0;
            if ($templateId <= 0) {
                throw new CatalogApiException('LATEX_VALIDATION_ERROR', 'Template ID is required.', 400);
            }

            $app = $this->createCatalogApplication();
            $template = $app->getLatexTemplateService()->getTemplate($templateId);
            $build = $app->getLatexBuildService()->build($templateId, (string) ($template['latex'] ?? ''));
            $updated = $app->getLatexTemplateService()->updatePdfPath(
                $templateId,
                (string) $build['relativePath'],
                $template['pdfPath'] ?? null
            );

            return [
                'data' => [
                    'pdfPath' => $updated['pdfPath'],
                    'downloadUrl' => $updated['downloadUrl'],
                    'updatedAt' => $updated['updatedAt'],
                    'stdout' => $build['stdout'],
                    'stderr' => $build['stderr'],
                    'exitCode' => $build['exitCode'],
                    'log' => $build['log'],
                    'correlationId' => $build['correlationId'],
                ],
                'context' => ['templateId' => $templateId],
            ];
        });
    }

    /**
     * Clear the catalog after the configured confirmation checks pass.
     */
    public function truncate(): HttpResponse
    {
        return $this->run('catalog.truncate', function (string $correlationId): array {
            $app = $this->createCatalogApplication();
            $payload = $this->input->json();
            if (! isset($payload['correlationId'])) {
                $payload['correlationId'] = $correlationId;
            }
            if (isset($payload['token']) && ! isset($payload['confirmToken'])) {
                $payload['confirmToken'] = $payload['token'];
            }

            return ['data' => $app->getTruncateService()->truncateCatalog($payload)];
        });
    }

    /**
     * Run one catalog API operation with its existing logs and response envelope.
     *
     * @param  callable(string): array<string, mixed>  $operation
     */
    private function run(
        string $action,
        callable $operation,
        string $genericErrorCode = 'internal_error',
        ?string $genericErrorMessage = 'Unexpected error'
    ): HttpResponse {
        $correlationId = $this->input->correlationId();
        $route = $this->input->route();
        $method = $this->input->method();
        $startedAt = microtime(true);
        Logger::info('request_start', ['route' => $route, 'method' => $method, 'action' => $action], $correlationId);

        try {
            $outcome = $operation($correlationId);
        } catch (CatalogApiException $e) {
            Logger::error('request_failed', [
                'route' => $route,
                'method' => $method,
                'action' => $action,
                'status' => $e->getStatusCode(),
                'errorCode' => $e->getErrorCode(),
                'exception' => $e,
                'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
            ], $correlationId);

            return Response::error($e->getErrorCode(), $e->getMessage(), $e->getStatusCode(), $correlationId);
        } catch (Throwable $e) {
            $errorMessage = $genericErrorMessage ?? $e->getMessage();
            Logger::error('request_failed', [
                'route' => $route,
                'method' => $method,
                'action' => $action,
                'status' => 500,
                'errorCode' => $genericErrorCode,
                'exception' => $e,
                'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
            ], $correlationId);

            return Response::error($genericErrorCode, $errorMessage, 500, $correlationId);
        }

        $status = (int) ($outcome['status'] ?? 200);
        $response = $outcome['response'] ?? Response::success($outcome['data'] ?? null, $status, $correlationId);
        $context = [
            'route' => $route,
            'method' => $method,
            'action' => $action,
            'status' => $status,
            'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
        if (isset($outcome['context']) && is_array($outcome['context'])) {
            $context = array_merge($context, $outcome['context']);
        }
        Logger::info('request_success', $context, $correlationId);

        return $response;
    }

    /** Create the application only when a catalog operation needs it. */
    private function createCatalogApplication(): CatalogController
    {
        $application = $this->catalogApplicationFactory !== null
            ? ($this->catalogApplicationFactory)()
            : app(CatalogController::class);
        $application->bootstrap();

        return $application;
    }
}
