<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\Nodes;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Modules\Catalog\Filament\Resources\CatalogResource;
use Modules\Catalog\Models\CatalogModel;
use Modules\Catalog\Models\CatalogNode;
use Modules\Catalog\Services\CatalogAdminService;
use UnitEnum;

class CatalogNodeResource extends CatalogResource
{
    protected static ?string $model = CatalogNode::class;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Categories and series';

    protected static ?string $modelLabel = 'catalog node';

    protected static ?string $pluralModelLabel = 'categories and series';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            Select::make('type')
                ->options([
                    'category' => 'Category',
                    'series' => 'Series',
                ])
                ->default('category')
                ->required()
                ->live(),
            Select::make('parent_id')
                ->label('Parent category')
                ->options(fn (?CatalogNode $record): array => static::categoryOptions($record))
                ->searchable()
                ->nullable()
                ->required(fn (Get $get): bool => $get('type') === 'series'),
            Toggle::make('typst_enabled')
                ->label('Enable Typst templates for this series')
                ->default(false)
                ->visible(fn (Get $get): bool => $get('type') === 'series'),
            TextInput::make('display_order')
                ->numeric()
                ->integer()
                ->default(0)
                ->required(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Node')
                ->columns(2)
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('type')->badge(),
                    TextEntry::make('parent.name')->placeholder('Root'),
                    TextEntry::make('display_order')->numeric(),
                    TextEntry::make('typst_templating_enabled')->label('Typst enabled')->badge(),
                    TextEntry::make('products_count')->label('Products'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('type')->badge()->sortable(),
                TextColumn::make('parent.name')->label('Parent')->placeholder('Root')->searchable(),
                TextColumn::make('display_order')->sortable(),
                TextColumn::make('products_count')->label('Products')->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->options([
                    'category' => 'Category',
                    'series' => 'Series',
                ]),
            ])
            ->defaultSort('display_order')
            ->recordActions([
                static::viewRecordAction(),
                static::editRecordAction(),
                Action::make('metadata')
                    ->label('Series metadata')
                    ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->visible(fn (CatalogNode $record): bool => $record->type === 'series')
                    ->authorize(fn (CatalogNode $record): bool => Auth::user()?->can('update', $record) ?? false)
                    ->fillForm(fn (CatalogNode $record): array => [
                        'metadata_values' => app(CatalogAdminService::class)->seriesValues($record),
                    ])
                    ->schema(fn (CatalogNode $record): array => static::dynamicValueInputs(
                        app(CatalogAdminService::class)->getMetadataFields((int) $record->getKey()),
                        'metadata_values',
                    ))
                    ->action(function (array $data, CatalogNode $record, array $mountedActions): void {
                        $values = $data['metadata_values'] ?? [];
                        try {
                            app(CatalogAdminService::class)->saveMetadata(
                                static::authenticatedUser(),
                                $record,
                                is_array($values) ? $values : [],
                            );
                        } catch (ValidationException $exception) {
                            $actionIndex = array_key_last($mountedActions) ?? 0;
                            $messages = [];
                            foreach ($exception->errors() as $path => $errors) {
                                $fieldPath = str_starts_with($path, 'data.') ? substr($path, 5) : $path;
                                $messages["mountedActions.{$actionIndex}.data.{$fieldPath}"] = $errors;
                            }

                            throw ValidationException::withMessages($messages);
                        }
                    })
                    ->successNotificationTitle('Series metadata saved'),
                static::deleteRecordAction(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('parent')
            ->withCount('products');
    }

    public static function saveRecord(User $actor, array $data, ?CatalogModel $record = null): CatalogModel
    {
        if ($record !== null && ! $record instanceof CatalogNode) {
            throw new AuthorizationException;
        }

        $nodeType = $data['type'] ?? $record?->type;
        if (array_key_exists('typst_enabled', $data)) {
            if ($nodeType === 'series') {
                $data['typst_templating_enabled'] = $data['typst_enabled'];
            }

            unset($data['typst_enabled']);
        }

        return app(CatalogAdminService::class)->saveNode($actor, $data, $record);
    }

    public static function mutateRecordFormData(array $data, CatalogModel $record): array
    {
        if (! $record instanceof CatalogNode) {
            throw new AuthorizationException;
        }

        $data['typst_enabled'] = (bool) $record->typst_templating_enabled;

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCatalogNodes::route('/'),
            'create' => Pages\CreateCatalogNode::route('/create'),
            'view' => Pages\ViewCatalogNode::route('/{record}'),
            'edit' => Pages\EditCatalogNode::route('/{record}/edit'),
        ];
    }
}
