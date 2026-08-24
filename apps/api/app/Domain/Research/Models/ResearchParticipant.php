<?php

namespace App\Domain\Research\Models;

use App\Domain\Identity\Models\StudentProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ResearchParticipant extends Model
{
    protected $fillable = [
        'research_study_id',
        'student_profile_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $participant): void {
            // ponytail: the unique index rejects simultaneous collisions; add insert retry for bulk enrollment.
            do {
                $participant->respondent_code = 'RSP-'.Str::upper(Str::random(6));
            } while (self::query()->where('respondent_code', $participant->respondent_code)->exists());
        });
    }

    public function researchStudy(): BelongsTo
    {
        return $this->belongsTo(ResearchStudy::class);
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }
}
