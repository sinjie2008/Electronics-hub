<?php

declare(strict_types=1);

namespace Modules\System\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class SystemPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'System';
    }

    public function getId(): string
    {
        return 'system';
    }

    public function boot(Panel $panel): void {}
}
