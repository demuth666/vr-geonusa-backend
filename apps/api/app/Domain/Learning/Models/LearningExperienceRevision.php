<?php

namespace App\Domain\Learning\Models;

use App\Domain\Heritage\Models\HeritageObject;
use App\Domain\Heritage\Models\PanoramaNode;
use App\Domain\Research\Models\ResearchStudy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use LogicException;

class LearningExperienceRevision extends Model
{
    protected $fillable = [
        'research_study_id',
        'version',
        'status',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $revision): void {
            if ($revision->getOriginal('status') === 'published') {
                throw new LogicException('Published Learning Experience Revisions are immutable.');
            }
        });

        static::deleting(function (self $revision): void {
            if ($revision->status === 'published') {
                throw new LogicException('Published Learning Experience Revisions cannot be deleted.');
            }
        });
    }

    public function researchStudy(): BelongsTo
    {
        return $this->belongsTo(ResearchStudy::class);
    }

    public function requiredPanoramaNodes(): BelongsToMany
    {
        return $this->belongsToMany(PanoramaNode::class, 'learning_experience_revision_panorama_nodes')
            ->orderBy('panorama_nodes.id');
    }

    public function requiredHeritageObjects(): BelongsToMany
    {
        return $this->belongsToMany(HeritageObject::class, 'learning_experience_revision_heritage_objects')
            ->orderBy('heritage_objects.id');
    }

    public function requiredQuizzes(): BelongsToMany
    {
        return $this->belongsToMany(Quiz::class, 'learning_experience_revision_quizzes')
            ->orderBy('quizzes.id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }
}
