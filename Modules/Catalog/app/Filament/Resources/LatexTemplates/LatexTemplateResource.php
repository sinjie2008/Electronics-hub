<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources\LatexTemplates;

use App\Models\User;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Catalog\Filament\Resources\CatalogResource;
use Modules\Catalog\Models\CatalogModel;
use Modules\Catalog\Models\LatexTemplate;
use Modules\Catalog\Services\CatalogAdminService;
use UnitEnum;

class LatexTemplateResource extends CatalogResource
{
    protected static ?string $model = LatexTemplate::class;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Legacy LaTeX templates';

    protected static ?string $modelLabel = 'legacy LaTeX template';

    protected static ?string $pluralModelLabel = 'legacy LaTeX templates';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCodeBracket;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Legacy template')
                ->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(255),
                    Textarea::make('description')
                        ->required()
                        ->rows(3),
                    Textarea::make('latex_source')
                        ->label('LaTeX source')
                        ->rows(24)
                        ->required()
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Legacy template')
                ->schema([
                    TextEntry::make('title'),
                    TextEntry::make('description')->placeholder('—'),
                    TextEntry::make('latex_source')->label('LaTeX source'),
                    TextEntry::make('pdf_path')->label('Generated PDF path')->placeholder('—'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('description')->searchable()->limit(80),
                TextColumn::make('pdf_path')->label('Generated PDF')->limit(60),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->defaultSort('title')
            ->recordActions([
                static::viewRecordAction(),
                static::editRecordAction(),
                static::deleteRecordAction(),
            ]);
    }

    public static function saveRecord(User $actor, array $data, ?CatalogModel $record = null): CatalogModel
    {
        if ($record !== null && ! $record instanceof LatexTemplate) {
            throw new AuthorizationException;
        }

        return app(CatalogAdminService::class)->saveLatexTemplate($actor, $data, $record);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLatexTemplates::route('/'),
            'create' => Pages\CreateLatexTemplate::route('/create'),
            'view' => Pages\ViewLatexTemplate::route('/{record}'),
            'edit' => Pages\EditLatexTemplate::route('/{record}/edit'),
        ];
    }
}
