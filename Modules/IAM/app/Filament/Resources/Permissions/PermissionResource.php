<?php

namespace Modules\IAM\Filament\Resources\Permissions;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Modules\IAM\Models\Permission;
use Modules\IAM\Services\PermissionManagementService;
use UnitEnum;

class PermissionResource extends Resource
{
    protected static ?string $model = Permission::class;

    protected static string|UnitEnum|null $navigationGroup = 'User Management';

    protected static ?string $navigationLabel = 'Permissions';

    protected static ?string $modelLabel = 'permission';

    protected static ?string $pluralModelLabel = 'permissions';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->disabled(fn (string $operation, ?Permission $record): bool => $operation === 'edit'
                    && (bool) ($record?->is_system || $record?->name === 'access.super-admin'))
                ->helperText('Use lowercase permission names such as reports.view.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('roles.name')->badge()->separator(', '),
                TextColumn::make('is_system')->badge()->formatStateUsing(fn (bool $state): string => $state ? 'System' : 'Custom'),
            ])
            ->defaultSort('name')
            ->recordActions([
                Action::make('edit')
                    ->authorize(function (Permission $record): bool {
                        $actor = Auth::user();

                        return $actor instanceof User
                            && $actor->can('update', $record)
                            && ! $record->is_system
                            && $record->name !== 'access.super-admin';
                    })
                    ->url(fn (Permission $record): string => self::getUrl('edit', ['record' => $record]))
                    ->icon(Heroicon::PencilSquare),
                Action::make('delete')
                    ->authorize(function (Permission $record): bool {
                        $actor = Auth::user();

                        return $actor instanceof User
                            && $actor->can('delete', $record)
                            && ! $record->is_system
                            && $record->name !== 'access.super-admin';
                    })
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Permission $record): void {
                        $actor = Auth::user();

                        abort_unless($actor instanceof User, 403);

                        app(PermissionManagementService::class)->delete($actor, $record);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPermissions::route('/'),
            'create' => Pages\CreatePermission::route('/create'),
            'edit' => Pages\EditPermission::route('/{record}/edit'),
        ];
    }
}
