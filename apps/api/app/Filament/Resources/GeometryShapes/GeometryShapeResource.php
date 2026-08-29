<?php

namespace App\Filament\Resources\GeometryShapes;

use App\Domain\Geometry\Models\GeometryShape;
use App\Filament\Resources\GeometryShapes\Pages\ManageGeometryShapes;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GeometryShapeResource extends Resource
{
    protected static ?string $model = GeometryShape::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|\UnitEnum|null $navigationGroup = 'Konten Geometri';

    protected static ?string $modelLabel = 'Bentuk Geometri';

    protected static ?string $pluralModelLabel = 'Bentuk Geometri';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
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
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageGeometryShapes::route('/'),
        ];
    }
}
