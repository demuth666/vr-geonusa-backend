<?php

namespace App\Filament\Resources\Quizzes;

use App\Domain\Geometry\Models\HeritageGeometryMapping;
use App\Domain\Learning\Models\Quiz;
use App\Filament\Resources\Quizzes\Pages\ManageQuizzes;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class QuizResource extends Resource
{
    protected static ?string $model = Quiz::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static string|\UnitEnum|null $navigationGroup = 'Konten Geometri';

    protected static ?string $modelLabel = 'Kuis Mikro';

    protected static ?string $pluralModelLabel = 'Kuis Mikro';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->label('Judul')
                ->required()
                ->maxLength(255),
            Select::make('heritage_geometry_mapping_id')
                ->label('Pemetaan Geometri Warisan')
                ->relationship(
                    'heritageGeometryMapping',
                    'id',
                    fn (Builder $query): Builder => $query->with(['heritageObject', 'geometryShape']),
                )
                ->getOptionLabelFromRecordUsing(
                    fn (HeritageGeometryMapping $mapping): string => "{$mapping->heritageObject->name} {$mapping->semantics} {$mapping->geometryShape->name}",
                )
                ->required()
                ->preload(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Judul')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('heritageGeometryMapping.heritageObject.name')
                    ->label('Objek Warisan'),
                TextColumn::make('heritageGeometryMapping.semantics')
                    ->label('Semantik'),
                TextColumn::make('heritageGeometryMapping.geometryShape.name')
                    ->label('Bentuk Geometri'),
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
            'index' => ManageQuizzes::route('/'),
        ];
    }
}
