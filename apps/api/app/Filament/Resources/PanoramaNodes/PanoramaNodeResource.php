<?php

namespace App\Filament\Resources\PanoramaNodes;

use App\Domain\Heritage\Models\PanoramaNode;
use App\Filament\Resources\PanoramaNodes\Pages\ManagePanoramaNodes;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

class PanoramaNodeResource extends Resource
{
    protected static ?string $model = PanoramaNode::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('heritage_area_id')
                ->relationship('heritageArea', 'name')
                ->required()
                ->searchable()
                ->preload(),
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            TextInput::make('slug')
                ->required()
                ->maxLength(255)
                ->unique(
                    table: PanoramaNode::class,
                    column: 'slug',
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                        ->where('heritage_area_id', $get('heritage_area_id')),
                ),
            TextInput::make('panorama_url')
                ->label('Panorama URL')
                ->required()
                ->url()
                ->maxLength(255)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('heritageArea.name')
                    ->label('Heritage area')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->searchable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePanoramaNodes::route('/'),
        ];
    }
}
