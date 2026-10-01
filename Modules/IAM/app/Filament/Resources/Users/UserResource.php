<?php

namespace Modules\IAM\Filament\Resources\Users;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Modules\IAM\Models\Role;
use Modules\IAM\Services\UserManagementService;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|UnitEnum|null $navigationGroup = 'User Management';

    protected static ?string $navigationLabel = 'Users';

    protected static ?string $modelLabel = 'user';

    protected static ?string $pluralModelLabel = 'users';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->email()
                ->required()
                ->maxLength(255),
            TextInput::make('password')
                ->password()
                ->revealable()
                ->required(fn (string $operation): bool => $operation === 'create')
                ->dehydrated(fn (?string $state, string $operation): bool => $operation === 'create' || filled($state))
                ->helperText('Leave blank when you do not want to change the password.'),
            TextInput::make('password_confirmation')
                ->password()
                ->revealable()
                ->required(fn (string $operation, Get $get): bool => $operation === 'create' || filled($get('password')))
                ->dehydrated(fn (?string $state, string $operation, Get $get): bool => $operation === 'create' || filled($get('password'))),
            Toggle::make('is_active')
                ->label('Active account')
                ->default(true),
            CheckboxList::make('role_ids')
                ->label('Roles')
                ->options(fn (): array => self::roleOptions())
                ->searchable()
                ->bulkToggleable()
                ->columns(2)
                ->dehydrated(fn (string $operation, ?User $record): bool => $operation === 'create'
                    || (Auth::user() instanceof User && Auth::user()->can('access.super-admin'))
                    || ($record instanceof User && Auth::user() instanceof User && ! $record->is(Auth::user()))
                ),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->sortable(),
                TextColumn::make('roles.name')->badge()->separator(', '),
                IconColumn::make('is_active')->boolean()->label('Active'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->recordActions([
                Action::make('edit')
                    ->authorize('update')
                    ->url(fn (User $record): string => self::getUrl('edit', ['record' => $record]))
                    ->icon(Heroicon::PencilSquare),
                Action::make('delete')
                    ->authorize(function (User $record): bool {
                        $actor = Auth::user();

                        return $actor instanceof User
                            && ! $actor->is($record)
                            && $actor->can('delete', $record);
                    })
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        $actor = Auth::user();

                        abort_unless($actor instanceof User, 403);

                        app(UserManagementService::class)->delete($actor, $record);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<int|string, string>
     */
    private static function roleOptions(): array
    {
        $actor = Auth::user();
        $roles = Role::query()->where('guard_name', 'web')->with('permissions')->orderBy('name')->get();

        if (! $actor instanceof User || ! $actor->can('access.super-admin')) {
            $roles = $roles->filter(function (Role $role) use ($actor): bool {
                if ($role->isPrivileged() || $role->permissions->contains('name', 'access.super-admin')) {
                    return false;
                }

                foreach ($role->permissions as $permission) {
                    if (! $actor instanceof User || ! $actor->can($permission->name)) {
                        return false;
                    }
                }

                return true;
            });
        }

        return $roles->pluck('name', 'id')->all();
    }
}
