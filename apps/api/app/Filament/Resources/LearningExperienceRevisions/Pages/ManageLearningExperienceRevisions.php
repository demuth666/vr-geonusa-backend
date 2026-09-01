<?php

namespace App\Filament\Resources\LearningExperienceRevisions\Pages;

use App\Domain\Learning\Actions\ManageLearningExperienceRevision;
use App\Filament\Resources\LearningExperienceRevisions\LearningExperienceRevisionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageLearningExperienceRevisions extends ManageRecords
{
    protected static string $resource = LearningExperienceRevisionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->using(fn (array $data) => app(ManageLearningExperienceRevision::class)->createDraft(
                $data['research_study_id'],
                $data['required_panorama_node_ids'],
                $data['required_heritage_object_ids'],
                $data['required_quiz_ids'],
            )),
        ];
    }
}
