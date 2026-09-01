<?php

namespace App\Filament\Resources\LearningExperienceRevisions;

use App\Domain\Heritage\Models\HeritageObject;
use App\Domain\Heritage\Models\PanoramaNode;
use App\Domain\Learning\Actions\ManageLearningExperienceRevision;
use App\Domain\Learning\Models\LearningExperienceRevision;
use App\Domain\Learning\Models\Quiz;
use App\Filament\Resources\LearningExperienceRevisions\Pages\ManageLearningExperienceRevisions;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LearningExperienceRevisionResource extends Resource
{
    protected static ?string $model = LearningExperienceRevision::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|\UnitEnum|null $navigationGroup = 'Pembelajaran';

    protected static ?string $modelLabel = 'Revisi Pengalaman Belajar';

    protected static ?string $pluralModelLabel = 'Revisi Pengalaman Belajar';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('research_study_id')
                ->relationship('researchStudy', 'name')
                ->required()
                ->disabledOn('edit'),
            Select::make('required_panorama_node_ids')
                ->multiple()
                ->options(PanoramaNode::query()->orderBy('name')->pluck('name', 'id')->all())
                ->required(),
            Select::make('required_heritage_object_ids')
                ->multiple()
                ->options(HeritageObject::query()->orderBy('name')->pluck('name', 'id')->all())
                ->required(),
            Select::make('required_quiz_ids')
                ->multiple()
                ->options(Quiz::query()->orderBy('title')->pluck('title', 'id')->all())
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('researchStudy.name')->label('Studi')->searchable()->sortable(),
            TextColumn::make('version')->label('Versi')->sortable(),
            TextColumn::make('status')->label('Status')->badge(),
            TextColumn::make('published_at')->label('Diterbitkan')->dateTime()->sortable(),
        ])->recordActions([
            Action::make('publish')
                ->authorize('update')
                ->visible(fn (LearningExperienceRevision $record) => $record->status === 'draft')
                ->action(fn (LearningExperienceRevision $record) => app(ManageLearningExperienceRevision::class)->publish($record)),
            EditAction::make()
                ->visible(fn (LearningExperienceRevision $record) => $record->status === 'draft')
                ->fillForm(fn (LearningExperienceRevision $record) => [
                    'research_study_id' => $record->research_study_id,
                    'required_panorama_node_ids' => $record->requiredPanoramaNodes()->pluck('panorama_nodes.id')->all(),
                    'required_heritage_object_ids' => $record->requiredHeritageObjects()->pluck('heritage_objects.id')->all(),
                    'required_quiz_ids' => $record->requiredQuizzes()->pluck('quizzes.id')->all(),
                ])
                ->using(fn (LearningExperienceRevision $record, array $data) => app(ManageLearningExperienceRevision::class)->replaceRequirements(
                    $record,
                    $data['required_panorama_node_ids'],
                    $data['required_heritage_object_ids'],
                    $data['required_quiz_ids'],
                )),
            DeleteAction::make()
                ->visible(fn (LearningExperienceRevision $record) => $record->status === 'draft'),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageLearningExperienceRevisions::route('/'),
        ];
    }
}
