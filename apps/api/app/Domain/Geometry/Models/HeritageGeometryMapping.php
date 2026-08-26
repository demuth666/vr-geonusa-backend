<?php

namespace App\Domain\Geometry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HeritageGeometryMapping extends Model
{
    protected $fillable = [
        'heritage_object_id',
        'geometry_shape_id',
        'semantics',
    ];

    public function geometryShape(): BelongsTo
    {
        return $this->belongsTo(GeometryShape::class);
    }

    public function learningObjectives(): HasMany
    {
        return $this->hasMany(LearningObjective::class)->orderBy('position');
    }
}
