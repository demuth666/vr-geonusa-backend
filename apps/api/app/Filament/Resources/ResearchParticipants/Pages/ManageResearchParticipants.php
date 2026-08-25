<?php

namespace App\Filament\Resources\ResearchParticipants\Pages;

use App\Filament\Resources\ResearchParticipants\ResearchParticipantResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageResearchParticipants extends ManageRecords
{
    protected static string $resource = ResearchParticipantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
