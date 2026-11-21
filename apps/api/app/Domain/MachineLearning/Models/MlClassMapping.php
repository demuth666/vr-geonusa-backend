<?php

namespace App\Domain\MachineLearning\Models;

use App\Domain\Geometry\Models\GeometryShape;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MlClassMapping extends Model
{
    protected $fillable = ['ml_model_version_id', 'geometry_shape_id', 'class_key'];

    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(MlModelVersion::class, 'ml_model_version_id');
    }

    public function geometryShape(): BelongsTo
    {
        return $this->belongsTo(GeometryShape::class);
    }
}
