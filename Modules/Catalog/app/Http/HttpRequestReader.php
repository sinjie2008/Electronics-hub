<?php

declare(strict_types=1);

namespace Modules\Catalog\Http;

use JsonException;

final class HttpRequestReader
{
    public function __construct(private RequestInput $input) {}

    /**
     * Ensures the request method matches expectations.
     *
     * @throws CatalogApiException When the HTTP verb is unexpected.
     */
    public function requireMethod(string $expected, string $actual): void
    {
        if (strcasecmp($expected, $actual) !== 0) {
            throw new CatalogApiException(
                'METHOD_NOT_ALLOWED',
                sprintf(
                    'Expected HTTP %s but received %s.',
                    strtoupper($expected),
                    strtoupper($actual)
                ),
                405,
                [
                    'expected' => strtoupper($expected),
                    'actual' => strtoupper($actual),
                ]
            );
        }
    }

    /**
     * Reads and decodes the JSON request body.
     *
     * @return array<string, mixed>
     *
     * @throws CatalogApiException When the payload is invalid JSON.
     */
    public function readJsonBody(): array
    {
        $raw = $this->input->body();
        if ($raw === '') {
            return [];
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new CatalogApiException(
                'INVALID_JSON',
                'Unable to parse JSON payload.',
                400,
                ['error' => $exception->getMessage()]
            );
        }

        if (! is_array($data)) {
            throw new CatalogApiException(
                'INVALID_JSON',
                'JSON payload must decode to an object.',
                400
            );
        }

        return $data;
    }

    /**
     * Reads JSON from either multipart form (metadata field) or raw body.
     *
     * @return array<string, mixed>
     *
     * @throws CatalogApiException When metadata is invalid JSON.
     */
    public function readJsonBodyOrMultipart(string $metadataField = 'metadata'): array
    {
        $contentType = $this->input->contentType();
        if (stripos($contentType, 'multipart/form-data') !== false) {
            $raw = (string) $this->input->form($metadataField, '');
            if ($raw === '') {
                return [];
            }
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new CatalogApiException(
                    'INVALID_JSON',
                    'Unable to parse metadata JSON.',
                    400,
                    ['error' => $exception->getMessage()]
                );
            }
            if (! is_array($decoded)) {
                throw new CatalogApiException('INVALID_JSON', 'Unable to parse metadata JSON.', 400);
            }

            return $decoded;
        }

        return $this->readJsonBody();
    }

    /**
     * Normalizes uploaded file arrays keyed by field key.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getUploadedFiles(string $field = 'files'): array
    {
        return $this->input->uploadedFiles($field);
    }
}
