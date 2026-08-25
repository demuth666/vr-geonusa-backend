<?php

namespace App\Filament\Resources\PanoramaNodes\Pages;

use App\Filament\Resources\PanoramaNodes\PanoramaNodeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePanoramaNodes extends ManageRecords
{
    protected static string $resource = PanoramaNodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
