<?php

declare(strict_types=1);

namespace Modules\System\Filament\Pages;

use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Modules\System\Services\ModuleManager;
use UnitEnum;

class ModuleManagement extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Module Management';

    protected static ?string $slug = 'system/modules';

    protected static ?int $navigationSort = 10;

    protected string $view = 'system::filament.pages.module-management';

    /** @var array<string, mixed>|null */
    public ?array $selectedModule = null;

    public static function canAccess(): bool
    {
        return Auth::user()?->can('modules.view') ?? false;
    }

    public function viewModule(string $name): void
    {
        $modules = app(ModuleManager::class)->list(Auth::user());
        $this->selectedModule = collect($modules)->firstWhere('name', $name);
        abort_unless($this->selectedModule !== null, 404);
    }

    public function enableModule(string $name): void
    {
        Gate::authorize('modules.view');
        app(ModuleManager::class)->enable(Auth::user(), $name);
        Notification::make()->title('Module enabled')->success()->send();
    }

    public function disableModule(string $name): void
    {
        Gate::authorize('modules.view');
        app(ModuleManager::class)->disable(Auth::user(), $name);
        Notification::make()->title('Module disabled')->success()->send();
    }

    protected function getViewData(): array
    {
        return ['modules' => app(ModuleManager::class)->list(Auth::user())];
    }
}
