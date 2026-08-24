<?php

namespace App\Domain\Learning\Actions;

use App\Domain\Identity\Models\StudentProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\LearningSession;
use App\Domain\Research\Models\ResearchParticipant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CreateLearningSession
{
    /** @return array{session: LearningSession, write_token: string} */
    public function handle(User $user, string $respondentCode): array
    {
        return DB::transaction(function () use ($user, $respondentCode) {
            $profile = StudentProfile::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $participant = ResearchParticipant::query()
                ->where('student_profile_id', $profile->id)
                ->where('respondent_code', $respondentCode)
                ->firstOrFail();

            if (LearningSession::query()->ownedBy($user)->active()->exists()) {
                throw new ConflictHttpException('An active learning session already exists.');
            }

            $writeToken = Str::random(64);
            $session = LearningSession::create([
                'research_participant_id' => $participant->id,
                'write_token_hash' => hash('sha256', $writeToken),
            ]);

            return [
                'session' => $session,
                'write_token' => $writeToken,
            ];
        });
    }
}
