<?php

declare(strict_types=1);

namespace Modules\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Support\ApplicationInfo;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ApplicationInfo::class);
    }
}
