<?php

namespace App\Domain\Research\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResearchStudy extends Model
{
    protected $fillable = [
        'name',
    ];

    public function participants(): HasMany
    {
        return $this->hasMany(ResearchParticipant::class);
    }

    public function assessmentInstruments(): HasMany
    {
        return $this->hasMany(AssessmentInstrument::class);
    }
}
