<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\Products;

use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
use Illuminate\Validation\Rules\Unique;
use Modules\Catalog\Filament\Resources\CatalogResource;
use Modules\Catalog\Models\CatalogModel;
use Modules\Catalog\Models\CatalogProduct;
use Modules\Catalog\Services\CatalogAdminService;
use UnitEnum;

class CatalogProductResource extends CatalogResource
{
    protected static ?string $model = CatalogProduct::class;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Products';

    protected static ?string $modelLabel = 'product';

    protected static ?string $pluralModelLabel = 'products';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('series_id')
                ->label('Series')
                ->options(fn (): array => static::seriesOptions())
                ->searchable()
                ->required()
                ->live()
                ->disabled(fn (string $operation): bool => $operation === 'edit')
                ->dehydrated(),
            TextInput::make('sku')
                ->label('SKU')
                ->required()
                ->maxLength(128)
                ->unique(
                    table: CatalogProduct::class,
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('series_id', $get('series_id')),
                ),
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            Textarea::make('description')
                ->maxLength(16000)
                ->rows(4)
                ->columnSpanFull(),
            Section::make('Product attributes')
                ->description('Edit attributes for the selected series. Use the Catalog interface to upload files.')
                ->columns(2)
                ->schema(fn (Get $get): array => self::productValueInputs((int) $get('series_id')))
                ->visible(fn (Get $get): bool => filled($get('series_id'))),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Product')
                ->columns(2)
                ->schema([
                    TextEntry::make('series.name')->label('Series'),
                    TextEntry::make('sku')->label('SKU'),
                    TextEntry::make('name'),
                    TextEntry::make('description')->columnSpanFull()->placeholder('—'),
                ]),
            Section::make('Product attributes')
                ->columns(2)
                ->schema(fn (CatalogProduct $record): array => static::dynamicValueEntries(
                    app(CatalogAdminService::class)->getProductFields((int) $record->series_id),
                    app(CatalogAdminService::class)->productValues($record),
                )),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')->label('SKU')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('series.name')->label('Series')->searchable()->sortable(),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('series_id')
                    ->label('Series')
                    ->options(fn (): array => static::seriesOptions()),
            ])
            ->defaultSort('name')
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
        if ($record !== null && ! $record instanceof CatalogProduct) {
            throw new AuthorizationException;
        }

        return app(CatalogAdminService::class)->saveProduct($actor, $data, $record);
    }

    public static function mutateRecordFormData(array $data, CatalogModel $record): array
    {
        if (! $record instanceof CatalogProduct) {
            throw new AuthorizationException;
        }

        $data['attribute_values'] = app(CatalogAdminService::class)->productValues($record);

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCatalogProducts::route('/'),
            'create' => Pages\CreateCatalogProduct::route('/create'),
            'view' => Pages\ViewCatalogProduct::route('/{record}'),
            'edit' => Pages\EditCatalogProduct::route('/{record}/edit'),
        ];
    }

    /** @return list<TextInput> */
    private static function productValueInputs(int $seriesId): array
    {
        if ($seriesId <= 0) {
            return [];
        }

        return static::dynamicValueInputs(
            app(CatalogAdminService::class)->getProductFields($seriesId),
            'attribute_values',
        );
    }
}
