<?php

declare(strict_types=1);

namespace Modules\System\Filament\Pages;

use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

final class ActivityLog extends Page implements HasTable
{
    use InteractsWithTable;

    /**
     * @var list<string>
     */
    private const SAFE_SETTING_PROPERTIES = [
        'application_name',
        'application_description',
        'timezone',
        'default_locale',
        'pagination_size',
        'default_ai_provider',
        'default_ai_model',
    ];

    protected static ?string $slug = 'system/activity-log';

    protected static ?string $title = 'Activity Log';

    protected static ?string $navigationLabel = 'Activity Log';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::ClipboardDocumentList;

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 60;

    protected string $view = 'system::filament.pages.activity-log';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('activity-log.view') ?? false;
    }

    public function mount(): void
    {
        Gate::authorize('activity-log.view');
    }

    public function table(Table $table): Table
    {
        Gate::authorize('activity-log.view');

        return $table
            ->query(Activity::query()
                ->inLog('administration')
                ->with('causer')
                ->orderByDesc('created_at')
                ->orderByDesc('id'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('description')
                    ->limit(120)
                    ->wrap(),
                TextColumn::make('causer.name')
                    ->label('Performed by')
                    ->placeholder('System'),
                TextColumn::make('subject_type')
                    ->label('Subject type')
                    ->placeholder('—'),
                TextColumn::make('subject_id')
                    ->label('Subject ID')
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('safeProperties')
                    ->label('View properties')
                    ->icon(Heroicon::Eye)
                    ->modalHeading('Safe activity properties')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->infolist([
                        TextEntry::make('context')
                            ->label('Action context')
                            ->state(fn (Activity $record): string => $this->encodeSafeContext($record)),
                        TextEntry::make('old_values')
                            ->label('Previous values')
                            ->state(fn (Activity $record): string => $this->encodeSafeProperties($record, 'old')),
                        TextEntry::make('new_values')
                            ->label('New values')
                            ->state(fn (Activity $record): string => $this->encodeSafeProperties($record, 'new')),
                    ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    private function encodeSafeContext(Activity $activity): string
    {
        Gate::authorize('activity-log.view');

        $properties = $activity->properties?->toArray() ?? [];
        $safeValues = [];

        foreach (['name', 'email', 'is_active', 'module', 'type', 'disk', 'source', 'client_name', 'grant_type'] as $key) {
            if (array_key_exists($key, $properties) && is_scalar($properties[$key])) {
                $safeValues[$key] = $properties[$key];
            }
        }

        return json_encode($safeValues, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function encodeSafeProperties(Activity $activity, string $side): string
    {
        Gate::authorize('activity-log.view');

        $properties = $activity->properties?->toArray() ?? [];
        $values = $properties[$side] ?? [];

        if (! is_array($values)) {
            return '{}';
        }

        $safeValues = [];

        foreach (self::SAFE_SETTING_PROPERTIES as $key) {
            $value = $values[$key] ?? null;

            if (is_scalar($value) || $value === null) {
                $safeValues[$key] = $value;
            }
        }

        return json_encode($safeValues, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
