<?php

namespace App\Filament\Resources\HeritageAreas;

use App\Domain\Heritage\Models\HeritageArea;
use App\Filament\Resources\HeritageAreas\Pages\ManageHeritageAreas;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class HeritageAreaResource extends Resource
{
    protected static ?string $model = HeritageArea::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static string|\UnitEnum|null $navigationGroup = 'Konten Warisan Budaya';

    protected static ?string $modelLabel = 'Area Warisan';

    protected static ?string $pluralModelLabel = 'Area Warisan';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('heritage_site_id')
                ->label('Situs Warisan')
                ->relationship('heritageSite', 'name')
                ->required()
                ->searchable()
                ->preload(),
            TextInput::make('name')
                ->label('Nama')
                ->required()
                ->maxLength(255)
                ->helperText('Slug unik dibuat otomatis saat disimpan.'),
            Textarea::make('description')
                ->label('Deskripsi')
                ->rows(4)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('heritageSite.name')
                    ->label('Situs Warisan')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable(),
                TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->databaseTransaction(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageHeritageAreas::route('/'),
        ];
    }
}
