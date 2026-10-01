<?php

namespace Modules\IAM\Filament\Resources\Roles;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Modules\IAM\Models\Permission;
use Modules\IAM\Models\Role;
use Modules\IAM\Services\RoleManagementService;
use UnitEnum;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|UnitEnum|null $navigationGroup = 'User Management';

    protected static ?string $navigationLabel = 'Roles';

    protected static ?string $modelLabel = 'role';

    protected static ?string $pluralModelLabel = 'roles';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->disabled(fn (string $operation, ?Role $record): bool => $operation === 'edit' && (bool) ($record?->isProtected() ?? false)),
            CheckboxList::make('permission_ids')
                ->label('Permissions')
                ->options(fn (): array => self::permissionOptions())
                ->searchable()
                ->bulkToggleable()
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('permissions.name')->badge()->separator(', '),
                TextColumn::make('users_count')->counts('users')->label('Users')->sortable(),
                TextColumn::make('is_system')->badge()->formatStateUsing(fn (bool $state): string => $state ? 'System' : 'Custom'),
            ])
            ->defaultSort('name')
            ->recordActions([
                Action::make('edit')
                    ->authorize('update')
                    ->url(fn (Role $record): string => self::getUrl('edit', ['record' => $record]))
                    ->icon(Heroicon::PencilSquare),
                Action::make('delete')
                    ->authorize(function (Role $record): bool {
                        $actor = Auth::user();

                        return $actor instanceof User
                            && $actor->can('delete', $record)
                            && ! $record->isProtected()
                            && $record->users()->doesntExist();
                    })
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Role $record): void {
                        $actor = Auth::user();

                        abort_unless($actor instanceof User, 403);

                        app(RoleManagementService::class)->delete($actor, $record);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<int|string, string>
     */
    private static function permissionOptions(): array
    {
        $actor = Auth::user();
        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->where('name', '!=', 'access.super-admin')
            ->orderBy('name')
            ->get();

        if (! $actor instanceof User || ! $actor->can('access.super-admin')) {
            $permissions = $permissions->filter(fn (Permission $permission): bool => $actor instanceof User
                && $actor->can($permission->name)
            );
        }

        return $permissions->pluck('name', 'id')->all();
    }
}
