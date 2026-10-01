<?php

declare(strict_types=1);

namespace Modules\Catalog\Http;

use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/** Keep the existing file endpoint's explicitly supplied cache directives. */
final class LegacyFileHeaders extends ResponseHeaderBag
{
    protected function computeCacheControlValue(): string
    {
        return $this->headers['cache-control'][0] ?? '';
    }
}
