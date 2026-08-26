<?php

namespace App\Filament\Resources\ResearchParticipants;

use App\Domain\Research\Models\ResearchParticipant;
use App\Filament\Resources\ResearchParticipants\Pages\ManageResearchParticipants;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

class ResearchParticipantResource extends Resource
{
    protected static ?string $model = ResearchParticipant::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|\UnitEnum|null $navigationGroup = 'Penelitian';

    protected static ?string $modelLabel = 'Partisipan Penelitian';

    protected static ?string $pluralModelLabel = 'Partisipan Penelitian';

    protected static ?string $recordTitleAttribute = 'respondent_code';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('research_study_id')
                ->label('Studi Penelitian')
                ->relationship('researchStudy', 'name')
                ->required()
                ->searchable()
                ->preload(),
            Select::make('student_profile_id')
                ->relationship('studentProfile', 'name')
                ->label('Siswa')
                ->nullable()
                ->searchable()
                ->preload()
                ->unique(
                    table: ResearchParticipant::class,
                    column: 'student_profile_id',
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                        ->where('research_study_id', $get('research_study_id')),
                ),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('respondent_code')
                    ->label('Kode Responden')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('researchStudy.name')
                    ->label('Studi Penelitian')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('studentProfile.name')
                    ->label('Siswa')
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
            'index' => ManageResearchParticipants::route('/'),
        ];
    }
}
