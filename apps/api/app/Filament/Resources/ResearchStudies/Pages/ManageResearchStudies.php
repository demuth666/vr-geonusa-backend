<?php

namespace App\Filament\Resources\ResearchStudies\Pages;

use App\Filament\Resources\ResearchStudies\ResearchStudyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageResearchStudies extends ManageRecords
{
    protected static string $resource = ResearchStudyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
