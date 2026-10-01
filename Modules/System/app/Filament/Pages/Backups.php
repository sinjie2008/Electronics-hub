<?php

declare(strict_types=1);

namespace Modules\System\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Modules\System\Models\BackupRun;
use Modules\System\Services\BackupManager;
use UnitEnum;

class Backups extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Backup Management';

    protected static ?string $slug = 'system/backups';

    protected static ?int $navigationSort = 20;

    protected string $view = 'system::filament.pages.backups';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('backups.view') ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('backupNow')->label('Backup Now')
            ->visible(fn (): bool => Auth::user()?->can('backups.create') ?? false)
            ->schema([Select::make('type')->label('Backup type')->options([
                'database' => 'Database backup', 'files' => 'Files backup', 'full' => 'Full backup',
            ])->default('database')->required()])
            ->requiresConfirmation()
            ->modalDescription('The backup will run on the backups queue. Archives contain private data.')
            ->action(function (array $data): void {
                Gate::authorize('backups.view');
                app(BackupManager::class)->request(Auth::user(), $data['type']);
                Notification::make()->title('Backup queued')->success()->send();
            })];
    }

    protected function getViewData(): array
    {
        Gate::authorize('backups.view');
        $manager = app(BackupManager::class);

        return [
            'archives' => $manager->archives(Auth::user()),
            'destinations' => $manager->health(Auth::user()),
            'runs' => BackupRun::latest()->limit(30)->get(),
        ];
    }
}
