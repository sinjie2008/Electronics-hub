<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\TypstTemplates;

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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Modules\Catalog\Filament\Resources\CatalogResource;
use Modules\Catalog\Models\CatalogModel;
use Modules\Catalog\Models\TypstTemplate;
use Modules\Catalog\Services\CatalogAdminService;
use UnitEnum;

class TypstTemplateResource extends CatalogResource
{
    protected static ?string $model = TypstTemplate::class;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Typst templates';

    protected static ?string $modelLabel = 'Typst template';

    protected static ?string $pluralModelLabel = 'Typst templates';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Template')
                ->columns(2)
                ->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(255),
                    Toggle::make('is_global')
                        ->label('Global template')
                        ->default(true)
                        ->required()
                        ->live()
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->dehydrated(),
                    Select::make('series_id')
                        ->label('Series')
                        ->options(fn (): array => static::seriesOptions())
                        ->searchable()
                        ->visible(fn (Get $get): bool => ! (bool) $get('is_global'))
                        ->required(fn (Get $get): bool => ! (bool) $get('is_global'))
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->dehydrated(),
                    Textarea::make('description')
                        ->rows(3)
                        ->columnSpanFull(),
                    Textarea::make('typst_content')
                        ->label('Typst source')
                        ->rows(24)
                        ->maxLength(1000000)
                        ->required()
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Template')
                ->columns(2)
                ->schema([
                    TextEntry::make('title'),
                    IconEntry::make('is_global')->label('Global template')->boolean(),
                    TextEntry::make('series.name')->label('Series')->placeholder('Global'),
                    TextEntry::make('last_pdf_generated_at')->dateTime()->placeholder('Never generated'),
                    TextEntry::make('description')->columnSpanFull()->placeholder('—'),
                    TextEntry::make('typst_content')->label('Typst source')->columnSpanFull(),
                    TextEntry::make('last_pdf_path')->label('Generated PDF path')->columnSpanFull()->placeholder('—'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('is_global')->label('Scope')->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Global' : 'Series'),
                TextColumn::make('series.name')->label('Series')->searchable()->sortable(),
                TextColumn::make('last_pdf_generated_at')->dateTime()->sortable(),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('is_global')->label('Scope')->options([
                    1 => 'Global',
                    0 => 'Series',
                ]),
                SelectFilter::make('series_id')
                    ->label('Series')
                    ->options(fn (): array => static::seriesOptions()),
            ])
            ->defaultSort('title')
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
        if ($record !== null && ! $record instanceof TypstTemplate) {
            throw new AuthorizationException;
        }

        return app(CatalogAdminService::class)->saveTypstTemplate($actor, $data, $record);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTypstTemplates::route('/'),
            'create' => Pages\CreateTypstTemplate::route('/create'),
            'view' => Pages\ViewTypstTemplate::route('/{record}'),
            'edit' => Pages\EditTypstTemplate::route('/{record}/edit'),
        ];
    }
}
