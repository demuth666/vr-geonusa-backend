<?php

namespace App\Domain\Heritage\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** @mixin Model */
trait HasUniqueSlug
{
    public static function bootHasUniqueSlug(): void
    {
        static::saving(function (Model $model): void {
            $scopeChanged = collect($model->slugScopeColumns())
                ->contains(fn (string $column): bool => $model->isDirty($column));

            if (filled($model->slug) && ! $model->isDirty('slug') && ! $scopeChanged) {
                return;
            }

            $model->slug = $model->nextUniqueSlug($model->slug ?: $model->name);
        });
    }

    /** @return list<string> */
    protected function slugScopeColumns(): array
    {
        return [];
    }

    private function nextUniqueSlug(string $value): string
    {
        $base = Str::substr(Str::slug($value) ?: 'item', 0, 240);
        $query = static::query();

        foreach ($this->slugScopeColumns() as $column) {
            $query->where($column, $this->getAttribute($column));
        }

        if ($this->exists) {
            $query->where($this->getKeyName(), '!=', $this->getKey());
        }

        $slug = $base;
        $suffix = 2;

        while ((clone $query)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
