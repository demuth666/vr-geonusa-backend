<?php

namespace App\Filament\Resources\HeritageGeometryMappings\Pages;

use App\Filament\Resources\HeritageGeometryMappings\HeritageGeometryMappingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageHeritageGeometryMappings extends ManageRecords
{
    protected static string $resource = HeritageGeometryMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
