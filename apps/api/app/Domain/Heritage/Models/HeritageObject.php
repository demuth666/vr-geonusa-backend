<?php

namespace App\Domain\Heritage\Models;

use App\Domain\Geometry\Models\HeritageGeometryMapping;
use App\Domain\Heritage\Models\Concerns\HasUniqueSlug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HeritageObject extends Model
{
    use HasUniqueSlug;

    protected $fillable = [
        'heritage_site_id',
        'name',
        'slug',
        'description',
    ];

    public function geometryMappings(): HasMany
    {
        return $this->hasMany(HeritageGeometryMapping::class)->orderBy('id');
    }

    protected function slugScopeColumns(): array
    {
        return ['heritage_site_id'];
    }
}
