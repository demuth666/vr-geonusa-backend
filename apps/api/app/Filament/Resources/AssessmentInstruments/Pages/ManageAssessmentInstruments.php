<?php

namespace App\Filament\Resources\AssessmentInstruments\Pages;

use App\Filament\Resources\AssessmentInstruments\AssessmentInstrumentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAssessmentInstruments extends ManageRecords
{
    protected static string $resource = AssessmentInstrumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
