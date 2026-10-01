<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Controllers;

use Closure;
use Modules\Catalog\Http\RequestInput;
use Modules\Catalog\Http\Response;
use Modules\Catalog\Services\LatexService;
use Modules\Catalog\Support\Logger;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Handles LaTeX template, variable, and compile API routes.
 */
final class LatexController
{
    /** @var (Closure(): LatexService)|null */
    private ?Closure $latexServiceFactory;

    /**
     * Accept an existing service or a lazy factory for host application bindings.
     *
     * The default leaves service creation inside each action's current try block.
     *
     * @param  (callable(): LatexService)|null  $latexServiceFactory
     */
    public function __construct(
        private RequestInput $input,
        private ?LatexService $latexService = null,
        ?callable $latexServiceFactory = null
    ) {
        $this->latexServiceFactory = $latexServiceFactory === null
            ? null
            : Closure::fromCallable($latexServiceFactory);
    }

    /**
     * Compile the supplied LaTeX document for a series.
     */
    public function compile(): HttpResponse
    {
        $correlationId = $this->input->correlationId();
        $route = $this->input->route();
        $method = $this->input->method();

        try {
            $service = $this->newService();
            if ($method === 'POST') {
                $data = $this->input->json();
                $latex = $data['latex'] ?? '';
                $seriesId = (int) ($data['series_id'] ?? 0);
                if (empty($latex) || $seriesId <= 0) {
                    return Response::error('validation_error', 'LaTeX content and Series ID are required', 400, $correlationId);
                }

                return Response::success($service->compileLatex($latex, $seriesId), 200, $correlationId);
            } else {
                return Response::error('method_not_allowed', 'Method not allowed', 405, $correlationId);
            }
        } catch (Throwable $e) {
            Logger::error('request_failed', ['route' => $route, 'method' => $method, 'exception' => $e], $correlationId);

            return Response::error('internal_error', 'Unexpected error: '.$e->getMessage(), 500, $correlationId);
        }
    }

    /**
     * Handle LaTeX template collection operations.
     */
    public function templates(): HttpResponse
    {
        $correlationId = $this->input->correlationId();
        $route = $this->input->route();
        $method = $this->input->method();

        try {
            $service = $this->newService();
            if ($method === 'GET') {
                $seriesId = (int) ($this->input->query('series_id') ?? 0);
                $templates = $seriesId > 0 ? $service->listSeriesTemplates($seriesId) : $service->listGlobalTemplates();

                return Response::success($templates, 200, $correlationId);
            } elseif ($method === 'POST') {
                $data = $this->normalizeTemplatePayload();
                $title = $data['title'];
                $latex = $data['latex'];
                $description = $data['description'];
                $seriesId = isset($data['seriesId']) ? (int) $data['seriesId'] : (int) ($this->input->query('series_id') ?? 0);
                if ($title === '') {
                    return Response::error('validation_error', 'Title is required', 400, $correlationId);
                }
                $template = $seriesId > 0
                    ? $service->createSeriesTemplate($seriesId, $title, $description, $latex)
                    : $service->createGlobalTemplate($title, $description, $latex);

                return Response::success($template, 201, $correlationId);
            } elseif ($method === 'PUT') {
                $data = $this->normalizeTemplatePayload();
                $id = $data['id'];
                $title = $data['title'];
                $latex = $data['latex'];
                $description = $data['description'];
                $seriesId = isset($data['seriesId']) ? (int) $data['seriesId'] : (int) ($this->input->query('series_id') ?? 0);
                if ($id <= 0 || $title === '') {
                    return Response::error('validation_error', 'ID and Title are required', 400, $correlationId);
                }
                $template = $seriesId > 0
                    ? $service->updateSeriesTemplate($id, $seriesId, $title, $description, $latex)
                    : $service->updateGlobalTemplate($id, $title, $description, $latex);
                if ($template === null) {
                    return Response::error('not_found', 'Template not found', 404, $correlationId);
                }

                return Response::success($template, 200, $correlationId);
            } elseif ($method === 'DELETE') {
                $id = (int) ($this->input->query('id') ?? 0);
                if (! $id) {
                    return Response::error('validation_error', 'ID is required', 400, $correlationId);
                }
                $service->deleteGlobalTemplate($id);

                return Response::success(['deleted' => true], 200, $correlationId);
            } else {
                return Response::error('method_not_allowed', 'Method not allowed', 405, $correlationId);
            }
        } catch (Throwable $e) {
            Logger::error('request_failed', ['route' => $route, 'method' => $method, 'exception' => $e], $correlationId);

            return Response::error('internal_error', 'Unexpected error: '.$e->getMessage(), 500, $correlationId);
        }
    }

    /**
     * Handle global LaTeX variable operations.
     */
    public function variables(): HttpResponse
    {
        $correlationId = $this->input->correlationId();
        $route = $this->input->route();
        $method = $this->input->method();

        try {
            $service = $this->newService();
            if ($method === 'GET') {
                return Response::success($service->listGlobalVariables(), 200, $correlationId);
            } elseif ($method === 'POST') {
                $data = $this->input->json();
                $key = trim((string) ($data['key'] ?? ''));
                $type = (string) ($data['type'] ?? 'text');
                $value = (string) ($data['value'] ?? '');
                $id = isset($data['id']) ? (int) $data['id'] : null;
                if ($key === '') {
                    return Response::error('validation_error', 'Key is required', 400, $correlationId);
                }
                $variable = $service->saveGlobalVariable($key, $type, $value, $id);
                if ($id !== null && $variable === null) {
                    return Response::error('not_found', 'Variable not found', 404, $correlationId);
                }

                return Response::success($variable, 200, $correlationId);
            } elseif ($method === 'DELETE') {
                $id = (int) ($this->input->query('id') ?? 0);
                if (! $id) {
                    return Response::error('validation_error', 'ID is required', 400, $correlationId);
                }
                $service->deleteGlobalVariable($id);

                return Response::success(['deleted' => true], 200, $correlationId);
            } else {
                return Response::error('method_not_allowed', 'Method not allowed', 405, $correlationId);
            }
        } catch (Throwable $e) {
            Logger::error('request_failed', ['route' => $route, 'method' => $method, 'exception' => $e], $correlationId);

            return Response::error('internal_error', 'Unexpected error: '.$e->getMessage(), 500, $correlationId);
        }
    }

    /**
     * Normalize JSON and form-encoded LaTeX template payloads.
     *
     * @return array{id:int,title:string,description:string,latex:string}
     */
    /**
     * @return array{id: int, title: string, description: string, latex: string, seriesId?: int}
     */
    private function normalizeTemplatePayload(): array
    {
        $data = $this->input->json();
        if (empty($data)) {
            $data = $this->input->forms();
        }

        return [
            'id' => isset($data['id']) ? (int) $data['id'] : 0,
            'title' => trim((string) ($data['title'] ?? $data['templateTitle'] ?? '')),
            'description' => trim((string) ($data['description'] ?? $data['templateDescription'] ?? '')),
            'latex' => (string) ($data['latex'] ?? $data['latex_content'] ?? $data['latex_code'] ?? ''),
        ];
    }

    /** Create or resolve the service only when an endpoint needs it. */
    private function newService(): LatexService
    {
        if ($this->latexService !== null) {
            return $this->latexService;
        }

        if ($this->latexServiceFactory !== null) {
            return ($this->latexServiceFactory)();
        }

        return new LatexService;
    }
}
