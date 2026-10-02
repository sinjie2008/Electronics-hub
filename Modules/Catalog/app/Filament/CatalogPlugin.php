<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Modules\Catalog\Providers\RouteServiceProvider;

class CatalogPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'Catalog';
    }

    public function getId(): string
    {
        return 'catalog';
    }

    /** Keep route and component caches independent of the module's current state. */
    public function register(Panel $panel): void
    {
        app()->register(RouteServiceProvider::class);
        $panel->pages(array_values(CatalogPage::DOCUMENT_PAGES));
    }

    public function boot(Panel $panel): void {}
}
