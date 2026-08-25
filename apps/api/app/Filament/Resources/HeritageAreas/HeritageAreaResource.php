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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

class HeritageAreaResource extends Resource
{
    protected static ?string $model = HeritageArea::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('heritage_site_id')
                ->relationship('heritageSite', 'name')
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
                    table: HeritageArea::class,
                    column: 'slug',
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                        ->where('heritage_site_id', $get('heritage_site_id')),
                ),
            Textarea::make('description')
                ->rows(4)
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
                TextColumn::make('heritageSite.name')
                    ->label('Heritage site')
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
            'index' => ManageHeritageAreas::route('/'),
        ];
    }
}
