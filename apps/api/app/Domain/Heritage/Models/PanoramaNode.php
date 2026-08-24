<?php

namespace App\Domain\Heritage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PanoramaNode extends Model
{
    protected $fillable = [
        'heritage_area_id',
        'name',
        'slug',
        'panorama_url',
    ];

    public function heritageArea(): BelongsTo
    {
        return $this->belongsTo(HeritageArea::class);
    }

    public function outgoingLinks(): HasMany
    {
        return $this->hasMany(PanoramaLink::class, 'source_node_id')->orderBy('id');
    }

    public function incomingLinks(): HasMany
    {
        return $this->hasMany(PanoramaLink::class, 'target_node_id')->orderBy('id');
    }
}
