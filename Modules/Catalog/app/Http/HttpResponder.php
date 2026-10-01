<?php

declare(strict_types=1);

namespace Modules\Catalog\Http;

use Illuminate\Http\Response;
use JsonException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class HttpResponder
{
    private ?string $correlationId = null;

    public function setCorrelationId(string $correlationId): void
    {
        $this->correlationId = $correlationId;
    }

    /**
     * Emits a JSON payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function sendJson(array $payload, int $statusCode = 200): Response
    {
        $headers = ['Content-Type' => 'application/json; charset=utf-8'];
        if ($this->correlationId) {
            $headers['X-Correlation-ID'] = $this->correlationId;
        }

        try {
            if ($this->correlationId) {
                $payload['correlationId'] = $payload['correlationId'] ?? $this->correlationId;
            }
            $body = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            $statusCode = 500;
            $fallback = [
                'success' => false,
                'errorCode' => 'ENCODING_ERROR',
                'message' => 'Unable to encode response payload.',
                'details' => ['error' => $exception->getMessage()],
            ];
            $body = json_encode($fallback);
        }
        $response = new Response($body, $statusCode, $headers);
        $response->headers->remove('Cache-Control');

        return $response;
    }

    /**
     * Emits an error response.
     *
     * @param  array<string, mixed>  $details
     */
    public function sendError(
        string $errorCode,
        string $message,
        int $statusCode = 400,
        array $details = []
    ): Response {
        return $this->sendJson(
            [
                'success' => false,
                'errorCode' => $errorCode,
                'message' => $message,
                'details' => $details,
            ],
            $statusCode
        );
    }

    public function sendFile(string $filePath, string $downloadName, string $contentType = 'text/csv'): StreamedResponse
    {
        if (! is_file($filePath)) {
            throw new CatalogApiException('CSV_NOT_FOUND', 'CSV file not found.', 404);
        }
        if (! is_readable($filePath)) {
            throw new CatalogApiException('CSV_READ_ERROR', 'Unable to stream CSV file.', 500);
        }
        if (str_starts_with($contentType, 'text/') && stripos($contentType, 'charset') === false) {
            $contentType .= ';charset='.(ini_get('default_charset') ?: 'UTF-8');
        }
        $headers = [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="'.basename($downloadName).'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ];
        if ($this->correlationId) {
            $headers['X-Correlation-ID'] = $this->correlationId;
        }
        $response = new StreamedResponse(static function () use ($filePath): void {
            readfile($filePath);
        });
        $response->headers = new LegacyFileHeaders($headers);

        return $response;
    }
}
