<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Controllers;

use Closure;
use Exception;
use InvalidArgumentException;
use Modules\Catalog\Http\RequestInput;
use Modules\Catalog\Http\Response;
use Modules\Catalog\Services\TypstService;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Handles Typst compile, template, preference, and variable API routes.
 */
final class TypstController
{
    /** @var (Closure(): TypstService)|null */
    private ?Closure $typstServiceFactory;

    /**
     * Accept an existing service or a lazy factory for host application bindings.
     *
     * The default preserves construction at each action's existing position,
     * including the legacy construction-before-try behavior.
     *
     * @param  (callable(): TypstService)|null  $typstServiceFactory
     */
    public function __construct(
        private RequestInput $input,
        private ?TypstService $typstService = null,
        ?callable $typstServiceFactory = null
    ) {
        $this->typstServiceFactory = $typstServiceFactory === null
            ? null
            : Closure::fromCallable($typstServiceFactory);
    }

    /**
     * Compile Typst source.
     */
    public function compile(): HttpResponse
    {
        $service = $this->newService();
        $method = $this->input->method();

        try {
            if ($method === 'POST') {
                $input = $this->input->json();
                $code = (string) ($input['typst'] ?? '');
                $seriesId = isset($input['seriesId']) ? (int) $input['seriesId'] : null;

                return Response::success($service->compileTypst($code, $seriesId));
            } else {
                return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
            }
        } catch (Exception $e) {
            return Response::error('COMPILE_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Handle Typst template collection operations.
     */
    public function templates(): HttpResponse
    {
        $service = $this->newService();
        $method = $this->input->method();

        try {
            if ($method === 'GET') {
                $id = $this->input->query('id');
                $seriesId = $this->input->query('seriesId');
                if ($id) {
                    $data = $service->getTemplate((int) $id);
                } elseif ($seriesId) {
                    $data = $service->listSeriesTemplates((int) $seriesId);
                } else {
                    $data = $service->listGlobalTemplates();
                }

                return Response::success($data);
            } elseif ($method === 'POST') {
                $input = $this->input->json();
                $title = (string) ($input['title'] ?? '');
                $description = (string) ($input['description'] ?? '');
                $code = (string) ($input['typst'] ?? '');
                $seriesId = isset($input['seriesId']) ? (int) $input['seriesId'] : null;
                $pdfPath = array_key_exists('lastPdfPath', $input) ? trim((string) $input['lastPdfPath']) : null;
                if ($pdfPath === '') {
                    $pdfPath = null;
                }

                $data = $seriesId
                    ? $service->createSeriesTemplate($seriesId, $title, $description, $code, $pdfPath)
                    : $service->createGlobalTemplate($title, $description, $code, $pdfPath);

                return Response::success($data);
            } elseif ($method === 'PUT') {
                $input = $this->input->json();
                $id = (int) ($input['id'] ?? 0);
                $title = (string) ($input['title'] ?? '');
                $description = (string) ($input['description'] ?? '');
                $code = (string) ($input['typst'] ?? '');
                $seriesId = isset($input['seriesId']) ? (int) $input['seriesId'] : null;
                $pdfPath = array_key_exists('lastPdfPath', $input) ? trim((string) $input['lastPdfPath']) : null;
                if ($pdfPath === '') {
                    $pdfPath = null;
                }

                $data = $seriesId
                    ? $service->updateSeriesTemplate($id, $seriesId, $title, $description, $code, $pdfPath)
                    : $service->updateGlobalTemplate($id, $title, $description, $code, $pdfPath);

                return Response::success($data);
            } elseif ($method === 'DELETE') {
                $id = $this->input->query('id');
                if (! $id) {
                    throw new InvalidArgumentException('Missing ID');
                }
                $service->deleteTemplate((int) $id);

                return Response::success(null);
            } else {
                return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
            }
        } catch (Exception $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Handle Typst global and series-scoped variable operations.
     */
    public function variables(): HttpResponse
    {
        $service = $this->newService();
        $method = $this->input->method();
        $correlationId = $this->input->correlationId();

        try {
            if ($method === 'GET') {
                $id = $this->input->query('id');
                $seriesId = (int) ($this->input->query('seriesId') ?? 0);
                if ($seriesId > 0) {
                    $data = $id ? $service->getScopedVariable((int) $id, $seriesId) : $service->listScopedVariables($seriesId);
                } else {
                    $data = $id ? $service->getGlobalVariable((int) $id) : $service->listGlobalVariables();
                }

                return Response::success($data, 200, $correlationId);
            } elseif ($method === 'POST') {
                $isMultipart = (stripos((string) $this->input->contentType(), 'multipart/form-data') !== false) || $this->input->hasUploads();
                if ($isMultipart) {
                    $key = trim((string) ($this->input->form('key') ?? ''));
                    $type = (string) ($this->input->form('type') ?? 'text');
                    $value = (string) ($this->input->form('value') ?? '');
                    $id = ($this->input->form('id') !== null) ? (int) $this->input->form('id') : null;
                    $seriesId = ($this->input->form('seriesId') !== null) ? (int) $this->input->form('seriesId') : 0;
                    if ($key === '') {
                        return Response::error('VALIDATION_ERROR', 'Key is required', 400, $correlationId);
                    }
                    if ($seriesId < 0) {
                        return Response::error('VALIDATION_ERROR', 'seriesId must be positive when provided.', 400, $correlationId);
                    }
                    $fileUpload = $this->input->upload('file');
                    $data = $seriesId > 0
                        ? $service->saveScopedVariable($seriesId, $key, $type, $value, $id, $fileUpload)
                        : $service->saveGlobalVariable($key, $type, $value, $id, $fileUpload);
                } else {
                    $input = $this->input->json();
                    $key = trim((string) ($input['key'] ?? ''));
                    $seriesId = isset($input['seriesId']) ? (int) $input['seriesId'] : 0;
                    if ($key === '') {
                        return Response::error('VALIDATION_ERROR', 'Key is required', 400, $correlationId);
                    }
                    if ($seriesId < 0) {
                        return Response::error('VALIDATION_ERROR', 'seriesId must be positive when provided.', 400, $correlationId);
                    }
                    $type = (string) ($input['type'] ?? 'text');
                    $value = (string) ($input['value'] ?? '');
                    $id = isset($input['id']) ? (int) $input['id'] : null;
                    $data = $seriesId > 0
                        ? $service->saveScopedVariable($seriesId, $key, $type, $value, $id)
                        : $service->saveGlobalVariable($key, $type, $value, $id);
                }

                return Response::success($data, 200, $correlationId);
            } elseif ($method === 'DELETE') {
                $id = $this->input->query('id');
                $seriesId = (int) ($this->input->query('seriesId') ?? 0);
                if (! $id || (int) $id <= 0) {
                    return Response::error('VALIDATION_ERROR', 'ID is required', 400, $correlationId);
                }
                if ($seriesId > 0) {
                    $service->deleteScopedVariable((int) $id, $seriesId);
                } else {
                    $service->deleteGlobalVariable((int) $id);
                }

                return Response::success(null, 200, $correlationId);
            } else {
                return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405, $correlationId);
            }
        } catch (Exception $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500, $correlationId);
        }
    }

    /**
     * Preserve the original Typst template contract at the legacy /api/ path.
     */
    public function legacyTemplates(): HttpResponse
    {
        $service = $this->newService();
        $method = $this->input->method();

        try {
            if ($method === 'GET') {
                $id = $this->input->query('id');
                $seriesId = $this->input->query('seriesId');
                if ($id) {
                    $data = $service->getTemplate((int) $id);
                } elseif ($seriesId) {
                    $data = $service->listSeriesTemplates((int) $seriesId);
                } else {
                    $data = $service->listGlobalTemplates();
                }

                return Response::success($data);
            } elseif ($method === 'POST') {
                $input = $this->input->json();
                $title = (string) ($input['title'] ?? '');
                $description = (string) ($input['description'] ?? '');
                $code = (string) ($input['typst'] ?? '');
                $pdfPath = isset($input['lastPdfPath']) ? (string) $input['lastPdfPath'] : null;
                $seriesId = isset($input['seriesId']) ? (int) $input['seriesId'] : null;
                $data = $seriesId
                    ? $service->createSeriesTemplate($seriesId, $title, $description, $code, $pdfPath)
                    : $service->createGlobalTemplate($title, $description, $code, $pdfPath);

                return Response::success($data);
            } elseif ($method === 'PUT') {
                $input = $this->input->json();
                $id = (int) ($input['id'] ?? 0);
                $title = (string) ($input['title'] ?? '');
                $description = (string) ($input['description'] ?? '');
                $code = (string) ($input['typst'] ?? '');
                $pdfPath = isset($input['lastPdfPath']) ? (string) $input['lastPdfPath'] : null;
                $seriesId = isset($input['seriesId']) ? (int) $input['seriesId'] : null;
                $data = $seriesId
                    ? $service->updateSeriesTemplate($id, $seriesId, $title, $description, $code, $pdfPath)
                    : $service->updateGlobalTemplate($id, $title, $description, $code, $pdfPath);

                return Response::success($data);
            } elseif ($method === 'DELETE') {
                // This older endpoint historically had no initialized $id and returns its error envelope.
                throw new InvalidArgumentException('Missing ID');
            } else {
                return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
            }
        } catch (Exception $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Preserve the original global-only variable contract at the legacy /api/ path.
     */
    public function legacyVariables(): HttpResponse
    {
        $service = $this->newService();
        $method = $this->input->method();

        try {
            if ($method === 'GET') {
                $id = $this->input->query('id');
                $data = $id ? $service->getGlobalVariable((int) $id) : $service->listGlobalVariables();

                return Response::success($data);
            } elseif ($method === 'POST') {
                $input = $this->input->json();
                $data = $service->saveGlobalVariable(
                    (string) ($input['key'] ?? ''),
                    (string) ($input['type'] ?? 'text'),
                    (string) ($input['value'] ?? ''),
                    isset($input['id']) ? (int) $input['id'] : null
                );

                return Response::success($data);
            } elseif ($method === 'DELETE') {
                $id = $this->input->query('id');
                if (! $id) {
                    throw new InvalidArgumentException('Missing ID');
                }
                $service->deleteGlobalVariable((int) $id);

                return Response::success(null);
            } else {
                return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
            }
        } catch (Exception $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Read and write the remembered global template for a series.
     */
    public function seriesPreferences(): HttpResponse
    {
        $service = $this->newService();
        $method = $this->input->method();

        try {
            if ($method === 'GET') {
                $seriesId = (int) ($this->input->query('seriesId') ?? 0);
                if ($seriesId <= 0) {
                    throw new InvalidArgumentException('seriesId is required.');
                }

                return Response::success($service->getSeriesPreference($seriesId));
            }

            if ($method === 'PUT') {
                $input = $this->input->json();
                $seriesId = (int) ($input['seriesId'] ?? 0);
                if ($seriesId <= 0) {
                    throw new InvalidArgumentException('seriesId is required.');
                }
                $lastGlobalTemplateId = $input['lastGlobalTemplateId'] ?? null;
                if ($lastGlobalTemplateId === '' || $lastGlobalTemplateId === false) {
                    $lastGlobalTemplateId = null;
                }
                if ($lastGlobalTemplateId !== null) {
                    if (! is_numeric($lastGlobalTemplateId)) {
                        throw new InvalidArgumentException('lastGlobalTemplateId must be numeric or null.');
                    }
                    $lastGlobalTemplateId = (int) $lastGlobalTemplateId;
                    if ($lastGlobalTemplateId <= 0) {
                        $lastGlobalTemplateId = null;
                    }
                }

                return Response::success($service->saveSeriesPreference($seriesId, $lastGlobalTemplateId));
            }

            return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 400);
        } catch (Exception $e) {
            return Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    /** Create or resolve the service only when an endpoint needs it. */
    private function newService(): TypstService
    {
        if ($this->typstService !== null) {
            return $this->typstService;
        }

        if ($this->typstServiceFactory !== null) {
            return ($this->typstServiceFactory)();
        }

        return new TypstService;
    }
}
