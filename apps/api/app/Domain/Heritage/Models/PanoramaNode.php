<?php

namespace App\Domain\Heritage\Models;

use App\Domain\Heritage\Models\Concerns\HasUniqueSlug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PanoramaNode extends Model
{
    use HasUniqueSlug;

    protected $fillable = [
        'heritage_area_id',
        'name',
        'slug',
        'panorama_url',
    ];

    protected static function booted(): void
    {
        static::updated(function (PanoramaNode $node): void {
            if ($node->wasChanged('panorama_url')) {
                $node->deletePanoramaAfterCommit($node->getRawOriginal('panorama_url'));
            }
        });

        static::deleted(fn (PanoramaNode $node) => $node->deletePanoramaAfterCommit(
            $node->getRawOriginal('panorama_url'),
        ));
    }

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

    public function annotations(): HasMany
    {
        return $this->hasMany(PanoramaObjectAnnotation::class)->orderBy('id');
    }

    public function panoramaUrl(): string
    {
        return filter_var($this->panorama_url, FILTER_VALIDATE_URL)
            ? $this->panorama_url
            : Storage::disk('s3')->url($this->panorama_url);
    }

    private function deletePanoramaAfterCommit(?string $path): void
    {
        if (blank($path) || filter_var($path, FILTER_VALIDATE_URL)) {
            return;
        }

        DB::afterCommit(fn () => Storage::disk('s3')->delete($path));
    }

    protected function slugScopeColumns(): array
    {
        return ['heritage_area_id'];
    }
}
