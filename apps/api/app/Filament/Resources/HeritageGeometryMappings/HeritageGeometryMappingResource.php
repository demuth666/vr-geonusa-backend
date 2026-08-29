<?php

namespace App\Filament\Resources\HeritageGeometryMappings;

use App\Domain\Geometry\Models\HeritageGeometryMapping;
use App\Filament\Resources\HeritageGeometryMappings\Pages\ManageHeritageGeometryMappings;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

class HeritageGeometryMappingResource extends Resource
{
    protected static ?string $model = HeritageGeometryMapping::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|\UnitEnum|null $navigationGroup = 'Konten Geometri';

    protected static ?string $modelLabel = 'Pemetaan Geometri Warisan';

    protected static ?string $pluralModelLabel = 'Pemetaan Geometri Warisan';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('heritage_object_id')
                ->label('Objek Warisan')
                ->relationship('heritageObject', 'name')
                ->required()
                ->searchable()
                ->preload(),
            Select::make('geometry_shape_id')
                ->label('Bentuk Geometri')
                ->relationship('geometryShape', 'name')
                ->required()
                ->searchable()
                ->preload()
                ->unique(
                    table: HeritageGeometryMapping::class,
                    column: 'geometry_shape_id',
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                        ->where('heritage_object_id', $get('heritage_object_id')),
                ),
            Select::make('semantics')
                ->label('Semantik')
                ->options(['didekati sebagai' => 'didekati sebagai'])
                ->default('didekati sebagai')
                ->rules(['in:didekati sebagai'])
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('heritageObject.name')
                    ->label('Objek Warisan')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('semantics')
                    ->label('Semantik'),
                TextColumn::make('geometryShape.name')
                    ->label('Bentuk Geometri')
                    ->searchable()
                    ->sortable(),
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
            'index' => ManageHeritageGeometryMappings::route('/'),
        ];
    }
}
