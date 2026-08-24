<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\StudentProfile;
use App\Domain\Research\Models\ResearchParticipant;
use App\Domain\Research\Models\ResearchStudy;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ResearchParticipantTest extends TestCase
{
    use RefreshDatabase;

    public function test_research_identity_is_pseudonymous_and_survives_profile_deletion(): void
    {
        $this->seed(UserSeeder::class);

        $profile = StudentProfile::query()->firstOrFail();
        $study = ResearchStudy::create(['name' => 'VR GeoNusa Borobudur Study']);
        $participant = ResearchParticipant::create([
            'research_study_id' => $study->id,
            'student_profile_id' => $profile->id,
        ]);
        $anonymousParticipant = ResearchParticipant::create([
            'research_study_id' => $study->id,
        ]);

        $this->assertMatchesRegularExpression('/^RSP-[A-Z0-9]{6}$/', $participant->respondent_code);
        $this->assertNotSame($participant->respondent_code, $anonymousParticipant->respondent_code);
        $this->assertTrue($participant->researchStudy->is($study));
        $this->assertTrue($participant->studentProfile->is($profile));
        $this->assertNotContains('name', Schema::getColumnListing('research_participants'));
        $this->assertNotContains('student_number', Schema::getColumnListing('research_participants'));

        $profile->delete();

        $this->assertNull($participant->fresh()->student_profile_id);
        $this->assertDatabaseCount('research_participants', 2);
    }
}
