<?php

namespace App\Filament\Resources\PanoramaLinks\Pages;

use App\Filament\Resources\PanoramaLinks\PanoramaLinkResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePanoramaLinks extends ManageRecords
{
    protected static string $resource = PanoramaLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
