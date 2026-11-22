<?php

namespace App\Filament\Resources\MlClassMappings;

use App\Domain\MachineLearning\Models\MlClassMapping;
use App\Domain\MachineLearning\Models\MlModelVersion;
use App\Filament\Resources\MlClassMappings\Pages\ManageMlClassMappings;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

class MlClassMappingResource extends Resource
{
    protected static ?string $model = MlClassMapping::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|\UnitEnum|null $navigationGroup = 'Machine Learning';

    protected static ?string $modelLabel = 'Pemetaan Kelas ML';

    protected static ?string $pluralModelLabel = 'Pemetaan Kelas ML';

    protected static ?string $recordTitleAttribute = 'class_key';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('ml_model_version_id')
                ->label('Versi Model ML')
                ->relationship(
                    'modelVersion',
                    'version',
                    modifyQueryUsing: fn (Builder $query): Builder => $query->with('model'),
                )
                ->getOptionLabelFromRecordUsing(
                    fn (MlModelVersion $record): string => "{$record->model->name} — {$record->version}",
                )
                ->searchable()
                ->preload()
                ->required(),
            TextInput::make('class_key')
                ->label('Class Key')
                ->required()
                ->maxLength(255)
                ->helperText('Label yang dilaporkan model, misalnya setengah_bola.')
                ->unique(
                    table: 'ml_class_mappings',
                    column: 'class_key',
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                        ->where('ml_model_version_id', $get('ml_model_version_id')),
                ),
            Select::make('geometry_shape_id')
                ->label('Bentuk Geometri')
                ->relationship('geometryShape', 'name')
                ->searchable()
                ->preload()
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('modelVersion.model.name')
                    ->label('Model ML'),
                TextColumn::make('modelVersion.version')
                    ->label('Versi')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('class_key')
                    ->label('Class Key')
                    ->searchable()
                    ->sortable(),
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
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMlClassMappings::route('/'),
        ];
    }
}
