<?php

namespace App\Domain\MachineLearning\Models;

use App\Domain\Heritage\Models\HeritageObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MlClassMapping extends Model
{
    protected $fillable = ['ml_model_version_id', 'heritage_object_id', 'class_key'];

    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(MlModelVersion::class, 'ml_model_version_id');
    }

    public function heritageObject(): BelongsTo
    {
        return $this->belongsTo(HeritageObject::class);
    }
}
