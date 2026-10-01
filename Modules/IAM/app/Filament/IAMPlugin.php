<?php

declare(strict_types=1);

namespace Modules\IAM\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class IAMPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'IAM';
    }

    public function getId(): string
    {
        return 'iam';
    }

    public function boot(Panel $panel): void {}
}
