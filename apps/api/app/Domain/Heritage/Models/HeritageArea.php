<?php

namespace App\Domain\Heritage\Models;

use App\Domain\Heritage\Models\Concerns\HasUniqueSlug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HeritageArea extends Model
{
    use HasUniqueSlug;

    protected $fillable = [
        'heritage_site_id',
        'name',
        'slug',
        'description',
    ];

    protected static function booted(): void
    {
        static::deleting(fn (HeritageArea $area) => $area->panoramaNodes()->get()->each->delete());
    }

    public function heritageSite(): BelongsTo
    {
        return $this->belongsTo(HeritageSite::class);
    }

    public function panoramaNodes(): HasMany
    {
        return $this->hasMany(PanoramaNode::class)->orderBy('id');
    }

    protected function slugScopeColumns(): array
    {
        return ['heritage_site_id'];
    }
}
