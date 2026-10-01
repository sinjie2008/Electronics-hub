<?php

namespace Tests\Fixtures;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class OptionalPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'Optional';
    }

    public function getId(): string
    {
        return 'optional-test';
    }

    public function boot(Panel $panel): void {}
}
