<?php

namespace App\Filament\Resources\MlModelVersions;

use App\Domain\MachineLearning\Models\MlModelVersion;
use App\Filament\Resources\MlModelVersions\Pages\ManageMlModelVersions;
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

class MlModelVersionResource extends Resource
{
    protected static ?string $model = MlModelVersion::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Machine Learning';

    protected static ?string $modelLabel = 'Versi Model ML';

    protected static ?string $pluralModelLabel = 'Versi Model ML';

    protected static ?string $recordTitleAttribute = 'version';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('ml_model_id')
                ->label('Model ML')
                ->relationship('model', 'name')
                ->required()
                ->searchable()
                ->preload(),
            TextInput::make('version')
                ->label('Versi')
                ->required()
                ->maxLength(255)
                ->unique(
                    table: 'ml_model_versions',
                    column: 'version',
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                        ->where('ml_model_id', $get('ml_model_id')),
                ),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('model.name')
                    ->label('Model ML')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('version')
                    ->label('Versi')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Diperbarui')
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
            'index' => ManageMlModelVersions::route('/'),
        ];
    }
}
