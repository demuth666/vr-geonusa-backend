<?php

namespace App\Filament\Resources\MlModels\Pages;

use App\Filament\Resources\MlModels\MlModelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMlModels extends ManageRecords
{
    protected static string $resource = MlModelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
