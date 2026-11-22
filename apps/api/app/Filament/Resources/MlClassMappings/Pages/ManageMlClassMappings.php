<?php

namespace App\Filament\Resources\MlClassMappings\Pages;

use App\Filament\Resources\MlClassMappings\MlClassMappingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMlClassMappings extends ManageRecords
{
    protected static string $resource = MlClassMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
