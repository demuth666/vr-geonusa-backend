<?php

namespace App\Filament\Resources\HeritageSites;

use App\Domain\Heritage\Models\HeritageSite;
use App\Filament\Resources\HeritageSites\Pages\ManageHeritageSites;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class HeritageSiteResource extends Resource
{
    protected static ?string $model = HeritageSite::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|\UnitEnum|null $navigationGroup = 'Konten Warisan Budaya';

    protected static ?string $modelLabel = 'Situs Warisan';

    protected static ?string $pluralModelLabel = 'Situs Warisan';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nama')
                ->required()
                ->maxLength(255)
                ->helperText('Slug dibuat otomatis saat disimpan.'),
            FileUpload::make('cover_image_url')
                ->label('Gambar Sampul')
                ->disk('s3')
                ->directory('heritage-sites/covers')
                ->visibility('public')
                ->image()
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(12 * 1024)
                ->preventFilePathTampering()
                ->openable()
                ->required()
                ->columnSpanFull(),
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
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageHeritageSites::route('/'),
        ];
    }
}
