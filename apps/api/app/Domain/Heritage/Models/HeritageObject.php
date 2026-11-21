<?php

namespace App\Domain\Heritage\Models;

use App\Domain\Geometry\Models\HeritageGeometryMapping;
use App\Domain\Heritage\Models\Concerns\HasUniqueSlug;
use App\Domain\Learning\Models\ActivityEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class HeritageObject extends Model
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
        static::updating(function (HeritageObject $heritageObject): void {
            if ($heritageObject->isDirty('heritage_site_id')) {
                throw new LogicException(
                    'Situs warisan pada objek tidak dapat diubah setelah dibuat.',
                );
            }
        });
    }

    public function heritageSite(): BelongsTo
    {
        return $this->belongsTo(HeritageSite::class);
    }

    public function geometryMappings(): HasMany
    {
        return $this->hasMany(HeritageGeometryMapping::class)->orderBy('id');
    }

    public function panoramaAnnotations(): HasMany
    {
        return $this->hasMany(PanoramaObjectAnnotation::class)->orderBy('id');
    }

    public function activityEvents(): HasMany
    {
        return $this->hasMany(ActivityEvent::class)->orderBy('id');
    }

    protected function slugScopeColumns(): array
    {
        return ['heritage_site_id'];
    }
}
