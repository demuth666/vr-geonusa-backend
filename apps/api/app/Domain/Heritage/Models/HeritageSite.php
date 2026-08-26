<?php

namespace App\Domain\Heritage\Models;

use App\Domain\Heritage\Models\Concerns\HasUniqueSlug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
        static::updated(function (HeritageSite $site): void {
            if ($site->wasChanged('cover_image_url')) {
                $site->deleteCoverAfterCommit($site->getRawOriginal('cover_image_url'));
            }
        });

        static::deleting(fn (HeritageSite $site) => $site->areas()->get()->each->delete());

        static::deleted(fn (HeritageSite $site) => $site->deleteCoverAfterCommit(
            $site->getRawOriginal('cover_image_url'),
        ));
    }

    public function areas(): HasMany
    {
        return $this->hasMany(HeritageArea::class)->orderBy('id');
    }

    public function coverImageUrl(): string
    {
        return filter_var($this->cover_image_url, FILTER_VALIDATE_URL)
            ? $this->cover_image_url
            : Storage::disk('s3')->url($this->cover_image_url);
    }

    private function deleteCoverAfterCommit(?string $path): void
    {
        if (blank($path) || filter_var($path, FILTER_VALIDATE_URL)) {
            return;
        }

        DB::afterCommit(fn () => Storage::disk('s3')->delete($path));
    }
}
