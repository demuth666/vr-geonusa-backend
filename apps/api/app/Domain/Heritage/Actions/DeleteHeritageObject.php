<?php

namespace App\Domain\Heritage\Actions;

use App\Domain\Heritage\Exceptions\HeritageObjectInUse;
use App\Domain\Heritage\Models\HeritageObject;
use Illuminate\Support\Facades\DB;

class DeleteHeritageObject
{
    public function execute(HeritageObject $heritageObject): void
    {
        DB::transaction(function () use ($heritageObject): void {
            $lockedObject = HeritageObject::query()
                ->lockForUpdate()
                ->findOrFail($heritageObject->getKey());

            if ($lockedObject->panoramaAnnotations()->exists()
                || $lockedObject->geometryMappings()->exists()
                || $lockedObject->activityEvents()->exists()
                || $lockedObject->mlClassMappings()->exists()) {
                throw new HeritageObjectInUse;
            }

            $lockedObject->delete();
        });
    }
}
