<?php

declare(strict_types=1);

namespace Modules\Catalog\Http;

use Illuminate\Http\Response as LaravelResponse;

/**
 * Helper for consistent JSON responses.
 */
final class Response
{
    /**
     * Send a success response envelope.
     *
     * @param  array<string, mixed>|list<mixed>|null  $data
     */
    public static function success(mixed $data, int $status = 200, ?string $correlationId = null): LaravelResponse
    {
        $cid = $correlationId ?? CorrelationId::generate();

        $response = new LaravelResponse(json_encode([
            'success' => true,
            'data' => $data,
            'correlationId' => $cid,
        ]), $status, ['Content-Type' => 'application/json; charset=utf-8', 'X-Correlation-ID' => $cid]);
        $response->headers->remove('Cache-Control');

        return $response;
    }

    public static function error(string $code, string $message, int $status = 500, ?string $correlationId = null): LaravelResponse
    {
        $cid = $correlationId ?? CorrelationId::generate();

        $response = new LaravelResponse(json_encode([
            'error' => [
                'code' => $code,
                'message' => $message,
                'correlationId' => $cid,
            ],
        ]), $status, ['Content-Type' => 'application/json; charset=utf-8', 'X-Correlation-ID' => $cid]);
        $response->headers->remove('Cache-Control');

        return $response;
    }
}
