<?php

namespace App\Filament\Resources\Questions;

use App\Domain\Learning\Actions\PrepareCorrectQuestionOption;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\QuestionOption;
use App\Domain\Learning\Rules\ValidQuestionOptions;
use App\Filament\Resources\Questions\Pages\ManageQuestions;
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

class QuestionResource extends Resource
{
    protected static ?string $model = Question::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static string|\UnitEnum|null $navigationGroup = 'Konten Geometri';

    protected static ?string $modelLabel = 'Pertanyaan Micro Quiz';

    protected static ?string $pluralModelLabel = 'Pertanyaan Micro Quiz';

    protected static ?string $recordTitleAttribute = 'prompt';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('quiz_id')
                ->label('Micro Quiz')
                ->relationship('quiz', 'title')
                ->required()
                ->searchable()
                ->preload(),
            TextInput::make('position')
                ->label('Urutan Pertanyaan')
                ->numeric()
                ->minValue(1)
                ->required()
                ->unique(
                    table: Question::class,
                    column: 'position',
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                        ->where('quiz_id', $get('quiz_id')),
                ),
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
                    Textarea::make('feedback')
                        ->label('Umpan Balik')
                        ->required()
                        ->columnSpanFull(),
                ])
                ->orderColumn('position')
                ->minItems(2)
                ->required()
                ->rules([new ValidQuestionOptions])
                ->mutateRelationshipDataBeforeFillUsing(function (array $data): array {
                    $option = QuestionOption::query()->findOrFail($data['id']);

                    return [...$data, ...$option->only(['is_correct', 'feedback'])];
                })
                ->mutateRelationshipDataBeforeCreateUsing(
                    function (array $data, Question $record): array {
                        app(PrepareCorrectQuestionOption::class)->execute(
                            $record,
                            (bool) ($data['is_correct'] ?? false),
                        );

                        return $data;
                    },
                )
                ->mutateRelationshipDataBeforeSaveUsing(
                    function (array $data, QuestionOption $record): array {
                        app(PrepareCorrectQuestionOption::class)->execute(
                            $record->question,
                            (bool) ($data['is_correct'] ?? false),
                            $record,
                        );

                        return $data;
                    },
                )
                ->columns(2)
                ->collapsible()
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('quiz.title')
                    ->label('Micro Quiz')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('position')
                    ->label('Urutan')
                    ->sortable(),
                TextColumn::make('prompt')
                    ->label('Pertanyaan')
                    ->searchable()
                    ->limit(30),
                TextColumn::make('options_count')
                    ->counts('options')
                    ->label('Jumlah Pilihan'),
            ])
            ->recordActions([
                EditAction::make()->databaseTransaction(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageQuestions::route('/'),
        ];
    }
}
