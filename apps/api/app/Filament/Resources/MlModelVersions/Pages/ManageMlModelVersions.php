<?php

namespace App\Filament\Resources\MlModelVersions\Pages;

use App\Filament\Resources\MlModelVersions\MlModelVersionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMlModelVersions extends ManageRecords
{
    protected static string $resource = MlModelVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
