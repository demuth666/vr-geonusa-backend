<?php

namespace App\Domain\Research\Models;

use App\Domain\Research\Enums\AssessmentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssessmentInstrument extends Model
{
    protected $fillable = [
        'research_study_id',
        'type',
        'title',
    ];

    protected function casts(): array
    {
        return [
            'type' => AssessmentType::class,
        ];
    }

    public function researchStudy(): BelongsTo
    {
        return $this->belongsTo(ResearchStudy::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(AssessmentItem::class)->orderBy('position');
    }
}
