<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\StudentProfile;
use App\Domain\Research\Models\ResearchParticipant;
use App\Domain\Research\Models\ResearchStudy;
use App\Filament\Resources\ResearchParticipants\Pages\ManageResearchParticipants;
use App\Filament\Resources\ResearchParticipants\ResearchParticipantResource;
use App\Filament\Resources\ResearchStudies\Pages\ManageResearchStudies;
use App\Filament\Resources\ResearchStudies\ResearchStudyResource;
use Database\Seeders\UserSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class ResearchBackOfficeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UserSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_internal_roles_can_view_research_resources(): void
    {
        foreach ([UserRole::SuperAdmin, UserRole::Researcher, UserRole::Teacher] as $role) {
            $user = $this->createUser($role);

            $this->assertTrue(Gate::forUser($user)->allows('viewAny', ResearchStudy::class));
            $this->assertTrue(Gate::forUser($user)->allows('viewAny', ResearchParticipant::class));
        }

        $this->actingAs($this->createUser(UserRole::Teacher))
            ->get(ResearchStudyResource::getUrl('index'))
            ->assertOk();
        $this->get(ResearchParticipantResource::getUrl('index'))
            ->assertOk();
    }

    public function test_students_cannot_access_research_resources(): void
    {
        $student = StudentProfile::query()->firstOrFail()->user;

        $this->assertFalse(Gate::forUser($student)->allows('viewAny', ResearchStudy::class));
        $this->assertFalse(Gate::forUser($student)->allows('viewAny', ResearchParticipant::class));
        $this->actingAs($student)
            ->get(ResearchStudyResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_only_super_admin_can_manage_research_records(): void
    {
        $profile = StudentProfile::query()->firstOrFail();
        $study = ResearchStudy::create(['name' => 'Borobudur Pilot']);
        $participant = ResearchParticipant::create([
            'research_study_id' => $study->id,
            'student_profile_id' => $profile->id,
        ]);
        $superAdmin = $this->createUser(UserRole::SuperAdmin);

        foreach ([ResearchStudy::class, ResearchParticipant::class] as $model) {
            $this->assertTrue(Gate::forUser($superAdmin)->allows('create', $model));
        }
        foreach ([$study, $participant] as $record) {
            $this->assertTrue(Gate::forUser($superAdmin)->allows('update', $record));
            $this->assertTrue(Gate::forUser($superAdmin)->allows('delete', $record));
        }

        foreach ([UserRole::Researcher, UserRole::Teacher] as $role) {
            $user = $this->createUser($role);

            $this->assertFalse(Gate::forUser($user)->allows('create', ResearchStudy::class));
            $this->assertFalse(Gate::forUser($user)->allows('create', ResearchParticipant::class));
            $this->assertFalse(Gate::forUser($user)->allows('update', $study));
            $this->assertFalse(Gate::forUser($user)->allows('delete', $participant));
        }

        $this->actingAs($this->createUser(UserRole::Teacher));

        Livewire::test(ManageResearchStudies::class)
            ->assertActionHidden('create')
            ->assertActionHidden(TestAction::make('edit')->table($study))
            ->assertActionHidden(TestAction::make('delete')->table($study));
        Livewire::test(ManageResearchParticipants::class)
            ->assertActionHidden('create')
            ->assertActionHidden(TestAction::make('edit')->table($participant))
            ->assertActionHidden(TestAction::make('delete')->table($participant));
    }

    public function test_filament_participant_can_start_a_student_api_session(): void
    {
        $profile = StudentProfile::query()->firstOrFail();

        $this->actingAs($this->createUser(UserRole::SuperAdmin));

        $this->callFilamentAction(
            Livewire::test(ManageResearchStudies::class),
            'create',
            ['name' => 'Borobudur Filament Study'],
        )
            ->assertHasNoActionErrors();

        $study = ResearchStudy::query()->where('name', 'Borobudur Filament Study')->firstOrFail();

        $this->callFilamentAction(
            Livewire::test(ManageResearchParticipants::class),
            'create',
            [
                'research_study_id' => $study->id,
                'student_profile_id' => $profile->id,
            ],
        )
            ->assertHasNoActionErrors();

        $participant = ResearchParticipant::query()->where('research_study_id', $study->id)->firstOrFail();

        $this->assertMatchesRegularExpression('/^RSP-[A-Z0-9]{6}$/', $participant->respondent_code);
        $this->assertArrayNotHasKey('name', $participant->getAttributes());
        $this->assertArrayNotHasKey('student_number', $participant->getAttributes());

        Sanctum::actingAs($profile->user);

        $this->postJson('/api/v1/learning-sessions', [
            'respondent_code' => $participant->respondent_code,
        ])
            ->assertCreated()
            ->assertJsonPath('data.respondent_code', $participant->respondent_code);
    }

    public function test_participant_form_rejects_duplicate_study_enrollment(): void
    {
        $profile = StudentProfile::query()->firstOrFail();
        $study = ResearchStudy::create(['name' => 'Borobudur Duplicate Test']);
        ResearchParticipant::create([
            'research_study_id' => $study->id,
            'student_profile_id' => $profile->id,
        ]);

        $this->actingAs($this->createUser(UserRole::SuperAdmin));

        $this->callFilamentAction(
            Livewire::test(ManageResearchParticipants::class),
            'create',
            [
                'research_study_id' => $study->id,
                'student_profile_id' => $profile->id,
            ],
        )
            ->assertHasActionErrors(['student_profile_id' => 'unique']);

        $this->assertDatabaseCount('research_participants', 1);
    }

}
