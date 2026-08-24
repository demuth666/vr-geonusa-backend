<?php

namespace App\Domain\Heritage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HeritageSite extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'cover_image_url',
    ];

    public function areas(): HasMany
    {
        return $this->hasMany(HeritageArea::class)->orderBy('id');
    }
}
