<?php

declare(strict_types=1);

namespace Modules\Catalog\Http;

use Illuminate\Http\Request;

/**
 * Generates correlation IDs for tracing requests.
 */
final class CorrelationId
{
    /**
     * Generate a new correlation ID (hex string).
     */
    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Extract a correlation ID from the current request headers or generate a new one.
     */
    public static function fromRequest(Request $request): string
    {
        $header = (string) $request->headers->get('X-Correlation-ID', '');
        $sanitized = self::sanitize($header);

        return $sanitized !== '' ? $sanitized : self::generate();
    }

    /**
     * Sanitize inbound correlation IDs to a safe subset and length.
     */
    private static function sanitize(string $value): string
    {
        $trimmed = substr($value, 0, 64);

        return preg_replace('/[^A-Za-z0-9\\-_.]/', '', $trimmed) ?: '';
    }
}
