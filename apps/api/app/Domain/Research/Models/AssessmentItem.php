<?php

namespace App\Domain\Research\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssessmentItem extends Model
{
    protected $fillable = [
        'assessment_instrument_id',
        'prompt',
        'position',
    ];

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(AssessmentInstrument::class, 'assessment_instrument_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(AssessmentOption::class)->orderBy('position');
    }
}
