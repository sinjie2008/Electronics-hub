<?php

declare(strict_types=1);

namespace Modules\Catalog\Http;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Read original values from the native, request-scoped Laravel request. */
final class RequestInput
{
    public function __construct(private Request $request) {}

    public function setRequest(Request $request): void
    {
        $this->request = $request;
    }

    public function body(): string
    {
        return $this->request->getContent();
    }

    public function json(): array
    {
        $decoded = json_decode($this->body(), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->request->query->all()[$key] ?? $default;
    }

    public function form(string $key, mixed $default = null): mixed
    {
        return $this->request->request->all()[$key] ?? $default;
    }

    public function forms(): array
    {
        return $this->request->request->all();
    }

    public function method(): string
    {
        return $this->request->getRealMethod();
    }

    public function route(): string
    {
        return $this->request->getRequestUri();
    }

    public function contentType(): string
    {
        return (string) $this->request->headers->get('Content-Type', '');
    }

    public function correlationId(): string
    {
        return CorrelationId::fromRequest($this->request);
    }

    public function hasUploads(): bool
    {
        return $this->request->files->all() !== [];
    }

    /** Keep the service upload metadata contract without reading PHP globals. */
    public function upload(string $field): ?array
    {
        if (! $this->request->files->has($field)) {
            return null;
        }

        return $this->normalizeUpload($this->request->files->get($field));
    }

    public function uploadedFiles(string $field = 'files'): array
    {
        $file = $this->request->files->get($field);
        if ($file instanceof UploadedFile) {
            return $file->getError() === UPLOAD_ERR_NO_FILE ? [] : ['file' => $this->normalizeUpload($file)];
        }
        $files = [];
        foreach (is_array($file) ? $file : [] as $key => $upload) {
            if ($upload instanceof UploadedFile && $upload->getError() !== UPLOAD_ERR_NO_FILE) {
                $files[$key] = $this->normalizeUpload($upload);
            }
        }

        return $files;
    }

    private function normalizeUpload(?UploadedFile $file): array
    {
        if ($file === null) {
            return ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];
        }

        return [
            'name' => $file->getClientOriginalName(),
            'type' => $file->getClientMimeType(),
            'tmp_name' => $file->getPathname(),
            'error' => $file->getError(),
            'size' => $file->getError() === UPLOAD_ERR_OK ? $file->getSize() : 0,
            'uploaded_file' => $file,
        ];
    }
}
