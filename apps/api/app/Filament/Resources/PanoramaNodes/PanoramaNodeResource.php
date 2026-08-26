<?php

namespace App\Filament\Resources\PanoramaNodes;

use App\Domain\Heritage\Models\PanoramaNode;
use App\Filament\Resources\PanoramaNodes\Pages\ManagePanoramaNodes;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PanoramaNodeResource extends Resource
{
    protected static ?string $model = PanoramaNode::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|\UnitEnum|null $navigationGroup = 'Konten Warisan Budaya';

    protected static ?string $modelLabel = 'Titik Panorama';

    protected static ?string $pluralModelLabel = 'Titik Panorama';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('heritage_area_id')
                ->label('Area Warisan')
                ->relationship('heritageArea', 'name')
                ->required()
                ->searchable()
                ->preload(),
            TextInput::make('name')
                ->label('Nama')
                ->required()
                ->maxLength(255)
                ->helperText('Slug unik dibuat otomatis saat disimpan.'),
            FileUpload::make('panorama_url')
                ->label('Gambar Panorama')
                ->disk('s3')
                ->directory('panoramas')
                ->visibility('public')
                ->image()
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(12 * 1024)
                ->preventFilePathTampering()
                ->openable()
                ->required()
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
                TextColumn::make('heritageArea.name')
                    ->label('Area Warisan')
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
                EditAction::make()->databaseTransaction(),
                DeleteAction::make()->databaseTransaction(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePanoramaNodes::route('/'),
        ];
    }
}
