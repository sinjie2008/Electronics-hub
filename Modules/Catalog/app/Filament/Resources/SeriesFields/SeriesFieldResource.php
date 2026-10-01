<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\SeriesFields;

use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;
use Modules\Catalog\Filament\Resources\CatalogResource;
use Modules\Catalog\Models\CatalogModel;
use Modules\Catalog\Models\SeriesField;
use Modules\Catalog\Services\CatalogAdminService;
use UnitEnum;

class SeriesFieldResource extends CatalogResource
{
    protected static ?string $model = SeriesField::class;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Series fields';

    protected static ?string $modelLabel = 'series field';

    protected static ?string $pluralModelLabel = 'series fields';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Definition')
                ->columns(2)
                ->schema([
                    Select::make('series_id')
                        ->label('Series')
                        ->options(fn (): array => static::seriesOptions())
                        ->searchable()
                        ->required()
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->dehydrated(),
                    Select::make('field_scope')
                        ->options([
                            'product_attribute' => 'Product attribute',
                            'series_metadata' => 'Series metadata',
                        ])
                        ->default('product_attribute')
                        ->required()
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->dehydrated(),
                    TextInput::make('field_key')
                        ->required()
                        ->maxLength(64)
                        ->unique(
                            table: SeriesField::class,
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                                ->where('series_id', $get('series_id'))
                                ->where('field_scope', $get('field_scope')),
                        ),
                    TextInput::make('label')
                        ->required()
                        ->maxLength(255),
                    Select::make('field_type')
                        ->options([
                            'text' => 'Text',
                            'number' => 'Number',
                            'file' => 'File (managed in Catalog legacy interface)',
                        ])
                        ->default('text')
                        ->required(),
                    TextInput::make('sort_order')
                        ->numeric()
                        ->integer()
                        ->default(0)
                        ->required(),
                    Textarea::make('default_value')
                        ->maxLength(16000)
                        ->rows(2)
                        ->columnSpanFull(),
                    Toggle::make('is_required')->default(false)->required(),
                    Toggle::make('is_public_portal_hidden')->label('Hidden from public portal')->default(false)->required(),
                    Toggle::make('is_backend_portal_hidden')->label('Hidden from backend portal')->default(false)->required(),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Field definition')
                ->columns(2)
                ->schema([
                    TextEntry::make('series.name')->label('Series'),
                    TextEntry::make('field_scope')->badge(),
                    TextEntry::make('field_key'),
                    TextEntry::make('label'),
                    TextEntry::make('field_type')->badge(),
                    TextEntry::make('default_value')->placeholder('—'),
                    TextEntry::make('sort_order'),
                    IconEntry::make('is_required')->boolean(),
                    IconEntry::make('is_public_portal_hidden')->label('Hidden from public portal')->boolean(),
                    IconEntry::make('is_backend_portal_hidden')->label('Hidden from backend portal')->boolean(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')->searchable()->sortable(),
                TextColumn::make('field_key')->searchable()->sortable(),
                TextColumn::make('series.name')->label('Series')->searchable()->sortable(),
                TextColumn::make('field_scope')->badge()->sortable(),
                TextColumn::make('field_type')->badge()->sortable(),
                IconColumn::make('is_required')->label('Required')->boolean()->sortable(),
                TextColumn::make('sort_order')->sortable(),
            ])
            ->filters([
                SelectFilter::make('series_id')
                    ->label('Series')
                    ->options(fn (): array => static::seriesOptions()),
                SelectFilter::make('field_scope')->options([
                    'product_attribute' => 'Product attribute',
                    'series_metadata' => 'Series metadata',
                ]),
                SelectFilter::make('field_type')->options([
                    'text' => 'Text',
                    'number' => 'Number',
                    'file' => 'File',
                ]),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                static::viewRecordAction(),
                static::editRecordAction(),
                static::deleteRecordAction(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('series');
    }

    public static function saveRecord(User $actor, array $data, ?CatalogModel $record = null): CatalogModel
    {
        if ($record !== null && ! $record instanceof SeriesField) {
            throw new AuthorizationException;
        }

        return app(CatalogAdminService::class)->saveField($actor, $data, $record);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSeriesFields::route('/'),
            'create' => Pages\CreateSeriesField::route('/create'),
            'view' => Pages\ViewSeriesField::route('/{record}'),
            'edit' => Pages\EditSeriesField::route('/{record}/edit'),
        ];
    }
}
