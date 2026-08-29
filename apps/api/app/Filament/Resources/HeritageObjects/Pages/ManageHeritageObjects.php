<?php

namespace App\Filament\Resources\HeritageObjects\Pages;

use App\Filament\Resources\HeritageObjects\HeritageObjectResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageHeritageObjects extends ManageRecords
{
    protected static string $resource = HeritageObjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
