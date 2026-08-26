<?php

namespace App\Filament\Resources\AssessmentInstruments;

use App\Domain\Research\Enums\AssessmentType;
use App\Domain\Research\Models\AssessmentInstrument;
use App\Domain\Research\Rules\ValidAssessmentItems;
use App\Filament\Resources\AssessmentInstruments\Pages\ManageAssessmentInstruments;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

class AssessmentInstrumentResource extends Resource
{
    protected static ?string $model = AssessmentInstrument::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|\UnitEnum|null $navigationGroup = 'Penelitian';

    protected static ?string $modelLabel = 'Instrumen Penilaian';

    protected static ?string $pluralModelLabel = 'Instrumen Penilaian';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('research_study_id')
                ->label('Studi Penelitian')
                ->relationship('researchStudy', 'name')
                ->required()
                ->searchable()
                ->preload(),
            Select::make('type')
                ->label('Jenis')
                ->options([
                    AssessmentType::Pretest->value => 'Tes Awal',
                    AssessmentType::Posttest->value => 'Tes Akhir',
                    AssessmentType::SelfEfficacy->value => 'Efikasi Diri',
                ])
                ->required()
                ->unique(
                    table: AssessmentInstrument::class,
                    column: 'type',
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                        ->where('research_study_id', $get('research_study_id')),
                ),
            TextInput::make('title')
                ->label('Judul')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
            Repeater::make('items')
                ->label('Butir Penilaian')
                ->relationship()
                ->schema([
                    Textarea::make('prompt')
                        ->label('Pertanyaan')
                        ->required()
                        ->columnSpanFull(),
                    Repeater::make('options')
                        ->label('Pilihan Jawaban')
                        ->relationship()
                        ->schema([
                            TextInput::make('text')
                                ->label('Teks Pilihan')
                                ->required()
                                ->maxLength(255),
                            Toggle::make('is_correct')
                                ->label('Jawaban Benar'),
                        ])
                        ->orderColumn('position')
                        ->minItems(2)
                        ->required()
                        ->columns(2)
                        ->columnSpanFull(),
                ])
                ->orderColumn('position')
                ->minItems(1)
                ->required()
                ->rules(fn (Get $get): array => [
                    new ValidAssessmentItems(AssessmentType::tryFrom((string) $get('type'))),
                ])
                ->collapsible()
                ->columnSpanFull(),
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
                TextColumn::make('researchStudy.name')
                    ->label('Studi Penelitian')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Jenis')
                    ->badge(),
                TextColumn::make('items_count')
                    ->counts('items')
                    ->label('Jumlah Item'),
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
            'index' => ManageAssessmentInstruments::route('/'),
        ];
    }
}
