<?php

namespace App\Filament\Resources\HeritageSites;

use App\Domain\Heritage\Models\HeritageSite;
use App\Filament\Resources\HeritageSites\Pages\ManageHeritageSites;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class HeritageSiteResource extends Resource
{
    protected static ?string $model = HeritageSite::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            TextInput::make('slug')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),
            TextInput::make('cover_image_url')
                ->label('Cover image URL')
                ->required()
                ->url()
                ->maxLength(255)
                ->columnSpanFull(),
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
                TextColumn::make('slug')
                    ->searchable(),
                TextColumn::make('updated_at')
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
            'index' => ManageHeritageSites::route('/'),
        ];
    }
}
