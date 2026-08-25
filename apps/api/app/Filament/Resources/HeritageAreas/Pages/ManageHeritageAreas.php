<?php

namespace App\Filament\Resources\HeritageAreas\Pages;

use App\Filament\Resources\HeritageAreas\HeritageAreaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageHeritageAreas extends ManageRecords
{
    protected static string $resource = HeritageAreaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
