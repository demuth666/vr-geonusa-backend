<?php

namespace App\Domain\Geometry\Models;

use App\Domain\Heritage\Models\HeritageObject;
use App\Domain\Learning\Models\Quiz;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class HeritageGeometryMapping extends Model
{
    protected $fillable = [
        'heritage_object_id',
        'geometry_shape_id',
        'semantics',
    ];

    public function heritageObject(): BelongsTo
    {
        return $this->belongsTo(HeritageObject::class);
    }

    public function geometryShape(): BelongsTo
    {
        return $this->belongsTo(GeometryShape::class);
    }

    public function learningObjectives(): HasMany
    {
        return $this->hasMany(LearningObjective::class)->orderBy('position');
    }

    public function quiz(): HasOne
    {
        return $this->hasOne(Quiz::class)->orderBy('id');
    }
}
