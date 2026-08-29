<?php

namespace App\Domain\Geometry\Models;

use App\Domain\Heritage\Models\Concerns\HasUniqueSlug;
use Illuminate\Database\Eloquent\Model;

class GeometryShape extends Model
{
    use HasUniqueSlug;

    protected $fillable = [
        'name',
        'slug',
        'description',
    ];
}
