<?php

namespace App\Filament\Resources\GeometryShapes\Pages;

use App\Filament\Resources\GeometryShapes\GeometryShapeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageGeometryShapes extends ManageRecords
{
    protected static string $resource = GeometryShapeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
