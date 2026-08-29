<?php

namespace Tests;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\StudentProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Enums\LearningSessionPhase;
use App\Domain\Learning\Models\LearningSession;
use App\Domain\Research\Models\ResearchParticipant;
use App\Domain\Research\Models\ResearchStudy;
use App\Domain\School\Models\School;
use Closure;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Livewire\Features\SupportTesting\Testable;

abstract class TestCase extends BaseTestCase
{
    /** @param array<string, mixed>|Closure(array<string, mixed>): array<string, mixed> $data */
    protected function callFilamentAction(
        Testable $component,
        string|TestAction $action,
        array|Closure $data,
    ): Testable {
        return $component->mountAction($action)->fillForm($data)->callMountedAction();
    }

    protected function createUser(UserRole $role): User
    {
        return User::create([
            'email' => "{$role->value}-".str()->random(8).'@example.test',
            'password' => 'password',
            'role' => $role,
        ]);
    }

    /** @return array{User, LearningSession, string} */
    protected function createLearningSession(
        string $prefix,
        string $suffix,
        bool $exploration = false,
    ): array {
        $school = School::firstOrCreate(['name' => 'SMP GeoNusa']);
        $user = User::create([
            'email' => "{$prefix}-{$suffix}@example.test",
            'password' => 'password',
        ]);
        $profile = StudentProfile::create([
            'user_id' => $user->id,
            'school_id' => $school->id,
            'name' => ucfirst($prefix)." Student {$suffix}",
            'student_number' => strtoupper($prefix)."-{$suffix}",
        ]);
        $study = ResearchStudy::firstOrCreate(['name' => 'VR GeoNusa Borobudur Study']);
        $participant = ResearchParticipant::create([
            'research_study_id' => $study->id,
            'student_profile_id' => $profile->id,
        ]);
        $writeToken = str_pad($suffix, 64, 'x');
        $session = LearningSession::create([
            'research_participant_id' => $participant->id,
            'write_token_hash' => hash('sha256', $writeToken),
        ]);

        if ($exploration) {
            $session->transitionTo(LearningSessionPhase::Exploration);
        }

        return [$user, $session, $writeToken];
    }
}
