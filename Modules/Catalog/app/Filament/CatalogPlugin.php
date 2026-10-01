<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

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

    public function boot(Panel $panel): void {}
}
