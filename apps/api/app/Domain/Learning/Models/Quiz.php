<?php

namespace App\Domain\Learning\Models;

use App\Domain\Geometry\Models\HeritageGeometryMapping;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quiz extends Model
{
    protected $fillable = [
        'heritage_geometry_mapping_id',
        'title',
    ];

    public function heritageGeometryMapping(): BelongsTo
    {
        return $this->belongsTo(HeritageGeometryMapping::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('position');
    }
}
