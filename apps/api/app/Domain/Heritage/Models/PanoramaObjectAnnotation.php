<?php

namespace App\Domain\Heritage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PanoramaObjectAnnotation extends Model
{
    protected $fillable = [
        'panorama_node_id',
        'heritage_object_id',
    ];

    public function heritageObject(): BelongsTo
    {
        return $this->belongsTo(HeritageObject::class);
    }
}
