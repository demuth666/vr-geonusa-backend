<?php

namespace App\Domain\Research\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\LearningSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssessmentAttempt extends Model
{
    protected $attributes = [
        'status' => 'draft',
    ];

    protected $fillable = [
        'learning_session_id',
        'assessment_instrument_id',
    ];

    protected $hidden = [
        'score',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
        ];
    }

    public function learningSession(): BelongsTo
    {
        return $this->belongsTo(LearningSession::class);
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(AssessmentInstrument::class, 'assessment_instrument_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(AssessmentAnswer::class);
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->whereHas(
            'learningSession',
            fn (Builder $query) => $query->ownedBy($user),
        );
    }
}
