<?php

declare(strict_types=1);

namespace Modules\System\Filament\Pages;

use Composer\InstalledVersions;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Support\ApplicationInfo;
use Nwidart\Modules\Facades\Module;
use Nwidart\Modules\Module as ModuleInstance;

final class SystemInformation extends Page
{
    protected static ?string $slug = 'system/information';

    protected static ?string $title = 'System Information';

    protected static ?string $navigationLabel = 'System Information';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::InformationCircle;

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 70;

    protected string $view = 'system::filament.pages.system-information';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('system-info.view') ?? false;
    }

    public function mount(): void
    {
        Gate::authorize('system-info.view');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        Gate::authorize('system-info.view');

        $applicationInfo = app(ApplicationInfo::class)->toArray();
        $databaseConnection = (string) config('database.default');
        $cacheStore = (string) config('cache.default');
        $queueConnection = (string) config('queue.default');

        $moduleNames = collect(Module::all())
            ->map(static fn (ModuleInstance $module): string => $module->getName())
            ->sort()
            ->values()
            ->all();

        return [
            'information' => [
                'Application name' => $applicationInfo['app_name'],
                'Application version' => (string) config('enterprise.version', 'Unknown'),
                'Laravel version' => $applicationInfo['laravel_version'],
                'PHP version' => PHP_VERSION,
                'Filament version' => InstalledVersions::getPrettyVersion('filament/filament') ?? 'Unknown',
                'Environment' => app()->environment(),
                'Database driver' => (string) config("database.connections.{$databaseConnection}.driver", 'Unknown'),
                'Cache driver' => (string) config("cache.stores.{$cacheStore}.driver", 'Unknown'),
                'Queue driver' => (string) config("queue.connections.{$queueConnection}.driver", 'Unknown'),
                'Installed modules' => $moduleNames,
            ],
        ];
    }
}
