<?php

namespace App\Domain\Learning\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuizAttempt extends Model
{
    protected $fillable = [
        'learning_session_id',
        'quiz_id',
    ];

    public function learningSession(): BelongsTo
    {
        return $this->belongsTo(LearningSession::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(QuizAnswer::class);
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->whereHas(
            'learningSession',
            fn (Builder $query) => $query->ownedBy($user),
        );
    }
}
