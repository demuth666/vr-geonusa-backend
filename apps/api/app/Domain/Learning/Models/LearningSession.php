<?php

namespace App\Domain\Learning\Models;

use App\Domain\Heritage\Models\PanoramaNode;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Enums\LearningSessionPhase;
use App\Domain\Research\Models\AssessmentAttempt;
use App\Domain\Research\Models\ResearchParticipant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class LearningSession extends Model
{
    protected $attributes = [
        'phase' => LearningSessionPhase::Pretest->value,
    ];

    protected $fillable = [
        'research_participant_id',
        'write_token_hash',
    ];

    protected $hidden = [
        'write_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'phase' => LearningSessionPhase::class,
        ];
    }

    public function researchParticipant(): BelongsTo
    {
        return $this->belongsTo(ResearchParticipant::class);
    }

    public function currentPanoramaNode(): BelongsTo
    {
        return $this->belongsTo(PanoramaNode::class, 'current_panorama_node_id');
    }

    public function activityEvents(): HasMany
    {
        return $this->hasMany(ActivityEvent::class);
    }

    public function assessmentAttempts(): HasMany
    {
        return $this->hasMany(AssessmentAttempt::class);
    }

    public function quizAttempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('phase', '!=', LearningSessionPhase::Completed->value);
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->whereHas(
            'researchParticipant.studentProfile',
            fn (Builder $query) => $query->where('user_id', $user->id),
        );
    }

    public function hasValidWriteToken(?string $token): bool
    {
        return is_string($token)
            && $token !== ''
            && hash_equals($this->write_token_hash, hash('sha256', $token));
    }

    public function transitionTo(LearningSessionPhase $phase): void
    {
        if ($this->phase->next() !== $phase) {
            throw new LogicException("Cannot transition from {$this->phase->value} to {$phase->value}.");
        }

        $updatedAt = now();
        $updated = self::query()
            ->whereKey($this->getKey())
            ->where('phase', $this->phase->value)
            ->update([
                'phase' => $phase->value,
                'updated_at' => $updatedAt,
            ]);

        if ($updated !== 1) {
            throw new LogicException('Learning session phase changed concurrently.');
        }

        $this->phase = $phase;
        $this->updated_at = $updatedAt;
    }
}
