<?php

declare(strict_types=1);

namespace Modules\Core\Support;

use Illuminate\Foundation\Application;

final readonly class ApplicationInfo
{
    public function __construct(private Application $app) {}

    /** @return array{app_name: string, laravel_version: string} */
    public function toArray(): array
    {
        return ['app_name' => (string) config('app.name'), 'laravel_version' => $this->app->version()];
    }
}
