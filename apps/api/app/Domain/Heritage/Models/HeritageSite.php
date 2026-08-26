<?php

namespace App\Domain\Heritage\Models;

use App\Domain\Heritage\Models\Concerns\HasUniqueSlug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HeritageSite extends Model
{
    use HasUniqueSlug;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'cover_image_url',
    ];

    protected static function booted(): void
    {
        static::deleting(fn (HeritageSite $site) => $site->areas()->get()->each->delete());
    }

    public function areas(): HasMany
    {
        return $this->hasMany(HeritageArea::class)->orderBy('id');
    }
}
