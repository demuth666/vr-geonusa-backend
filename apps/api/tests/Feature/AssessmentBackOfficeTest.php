<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\StudentProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Research\Models\AssessmentInstrument;
use App\Domain\Research\Models\ResearchParticipant;
use App\Domain\Research\Models\ResearchStudy;
use App\Filament\Resources\AssessmentInstruments\AssessmentInstrumentResource;
use App\Filament\Resources\AssessmentInstruments\Pages\ManageAssessmentInstruments;
use Database\Seeders\UserSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class AssessmentBackOfficeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UserSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_internal_roles_can_view_assessment_instruments_but_students_cannot(): void
    {
        foreach ([UserRole::SuperAdmin, UserRole::Researcher, UserRole::Teacher] as $role) {
            $this->assertTrue(Gate::forUser($this->createUser($role))->allows('viewAny', AssessmentInstrument::class));
        }

        $student = StudentProfile::query()->firstOrFail()->user;

        $this->assertFalse(Gate::forUser($student)->allows('viewAny', AssessmentInstrument::class));
        $this->actingAs($student)
            ->get(AssessmentInstrumentResource::getUrl('index'))
            ->assertForbidden();

        $this->actingAs($this->createUser(UserRole::Teacher))
            ->get(AssessmentInstrumentResource::getUrl('index'))
            ->assertOk();
    }

    public function test_only_super_admin_can_manage_assessment_instruments(): void
    {
        $study = ResearchStudy::create(['name' => 'Borobudur Assessment Policy']);
        $instrument = AssessmentInstrument::create([
            'research_study_id' => $study->id,
            'type' => 'pretest',
            'title' => 'Policy Pretest',
        ]);
        $superAdmin = $this->createUser(UserRole::SuperAdmin);

        $this->assertTrue(Gate::forUser($superAdmin)->allows('create', AssessmentInstrument::class));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('update', $instrument));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('delete', $instrument));

        $teacher = $this->createUser(UserRole::Teacher);
        $this->assertFalse(Gate::forUser($teacher)->allows('create', AssessmentInstrument::class));
        $this->assertFalse(Gate::forUser($teacher)->allows('update', $instrument));
        $this->assertFalse(Gate::forUser($teacher)->allows('delete', $instrument));

        $this->actingAs($teacher);

        Livewire::test(ManageAssessmentInstruments::class)
            ->assertActionHidden('create')
            ->assertActionHidden(TestAction::make('edit')->table($instrument))
            ->assertActionHidden(TestAction::make('delete')->table($instrument));
    }

    public function test_filament_authored_pretest_is_used_by_student_api_without_answer_leaks(): void
    {
        $study = ResearchStudy::create(['name' => 'Borobudur Filament Assessment']);

        $this->actingAs($this->createUser(UserRole::SuperAdmin));

        $this->callFilamentAction(
            Livewire::test(ManageAssessmentInstruments::class),
            'create',
            fn (array $state): array => $this->instrumentFormState(
                $state,
                $study->id,
                'pretest',
                true,
            ),
        )
            ->assertHasNoActionErrors();

        $instrument = AssessmentInstrument::query()->with('items.options')->sole();
        $this->assertSame([1], $instrument->items->pluck('position')->all());
        $this->assertSame([1, 2], $instrument->items->sole()->options->pluck('position')->all());
        $this->assertSame(1, $instrument->items->sole()->options->where('is_correct', true)->count());

        $profile = StudentProfile::query()->firstOrFail();
        $participant = ResearchParticipant::create([
            'research_study_id' => $study->id,
            'student_profile_id' => $profile->id,
        ]);
        Sanctum::actingAs($profile->user);

        $session = $this->postJson('/api/v1/learning-sessions', [
            'respondent_code' => $participant->respondent_code,
        ])->assertCreated();

        $response = $this->postJson(
            "/api/v1/learning-sessions/{$session->json('data.id')}/assessment-attempts",
            [],
            ['X-Session-Write-Token' => $session->json('data.write_token')],
        )
            ->assertCreated()
            ->assertJsonPath('data.title', 'Filament Pretest')
            ->assertJsonPath('data.items.0.prompt', 'Bentuk apa yang mendekati stupa?')
            ->assertJsonPath('data.items.0.options.0.text', 'Setengah bola');

        $this->assertStringNotContainsString('is_correct', $response->getContent());
    }

    public function test_instrument_form_enforces_correct_answer_semantics(): void
    {
        $study = ResearchStudy::create(['name' => 'Borobudur Validation']);
        $this->actingAs($this->createUser(UserRole::SuperAdmin));

        $this->callFilamentAction(
            Livewire::test(ManageAssessmentInstruments::class),
            'create',
            fn (array $state): array => $this->instrumentFormState(
                $state,
                $study->id,
                'self_efficacy',
                true,
            ),
        )
            ->assertHasActionErrors(['items']);

        $this->assertDatabaseCount('assessment_instruments', 0);
    }

    private function createUser(UserRole $role): User
    {
        return User::create([
            'email' => "{$role->value}-".str()->random(8).'@example.test',
            'password' => 'password',
            'role' => $role,
        ]);
    }

    /** @param array<string, mixed> $state */
    private function instrumentFormState(
        array $state,
        int $studyId,
        string $type,
        bool $firstOptionIsCorrect,
    ): array {
        $itemKey = array_key_first($state['items']);
        $optionKey = array_key_first($state['items'][$itemKey]['options']);

        return [
            'research_study_id' => $studyId,
            'type' => $type,
            'title' => $type === 'pretest' ? 'Filament Pretest' : 'Invalid Self-Efficacy',
            'items' => [
                $itemKey => [
                    'prompt' => $type === 'pretest'
                        ? 'Bentuk apa yang mendekati stupa?'
                        : 'Saya yakin memahami geometri.',
                    'options' => [
                        $optionKey => [
                            'text' => $type === 'pretest' ? 'Setengah bola' : 'Setuju',
                            'is_correct' => $firstOptionIsCorrect,
                        ],
                        (string) Str::uuid() => [
                            'text' => $type === 'pretest' ? 'Kubus' : 'Tidak setuju',
                            'is_correct' => false,
                        ],
                    ],
                ],
            ],
        ];
    }
}
