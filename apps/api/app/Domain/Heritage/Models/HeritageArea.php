<?php

namespace App\Domain\Heritage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HeritageArea extends Model
{
    protected $fillable = [
        'heritage_site_id',
        'name',
        'slug',
        'description',
    ];

    public function heritageSite(): BelongsTo
    {
        return $this->belongsTo(HeritageSite::class);
    }

    public function panoramaNodes(): HasMany
    {
        return $this->hasMany(PanoramaNode::class)->orderBy('id');
    }
}
