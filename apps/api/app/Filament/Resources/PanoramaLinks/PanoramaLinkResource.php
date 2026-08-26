<?php

namespace App\Filament\Resources\PanoramaLinks;

use App\Domain\Heritage\Models\PanoramaLink;
use App\Filament\Resources\PanoramaLinks\Pages\ManagePanoramaLinks;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

class PanoramaLinkResource extends Resource
{
    protected static ?string $model = PanoramaLink::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|\UnitEnum|null $navigationGroup = 'Konten Warisan Budaya';

    protected static ?string $modelLabel = 'Tautan Panorama';

    protected static ?string $pluralModelLabel = 'Tautan Panorama';

    protected static ?string $recordTitleAttribute = 'label';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('source_node_id')
                ->label('Titik Sumber')
                ->relationship('sourceNode', 'name')
                ->required()
                ->searchable()
                ->preload(),
            Select::make('target_node_id')
                ->label('Titik Tujuan')
                ->relationship('targetNode', 'name')
                ->required()
                ->searchable()
                ->preload()
                ->unique(
                    table: PanoramaLink::class,
                    column: 'target_node_id',
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                        ->where('source_node_id', $get('source_node_id')),
                ),
            TextInput::make('label')
                ->label('Label')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
            TextInput::make('yaw')
                ->label('Sudut Horizontal (Yaw)')
                ->required()
                ->numeric()
                ->step(0.001),
            TextInput::make('pitch')
                ->label('Sudut Vertikal (Pitch)')
                ->required()
                ->numeric()
                ->default(0)
                ->step(0.001),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sourceNode.name')
                    ->label('Sumber')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('targetNode.name')
                    ->label('Tujuan')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('label')
                    ->label('Label')
                    ->searchable(),
                TextColumn::make('yaw')
                    ->label('Yaw')
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('pitch')
                    ->label('Pitch')
                    ->numeric(decimalPlaces: 3),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePanoramaLinks::route('/'),
        ];
    }
}
