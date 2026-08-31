<?php

namespace Tests\Feature;

use App\Domain\Geometry\Models\GeometryShape;
use App\Domain\Geometry\Models\HeritageGeometryMapping;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Learning\Models\Quiz;
use App\Filament\Resources\Quizzes\Pages\ManageQuizzes;
use App\Filament\Resources\Quizzes\QuizResource;
use Database\Seeders\BorobudurSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class QuizBackOfficeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BorobudurSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_internal_roles_can_view_micro_quizzes_but_students_cannot(): void
    {
        foreach ([UserRole::SuperAdmin, UserRole::Researcher, UserRole::Teacher] as $role) {
            $this->assertTrue(Gate::forUser($this->createUser($role))->allows('viewAny', Quiz::class));
        }

        $this->actingAs($this->createUser(UserRole::Teacher))
            ->get(QuizResource::getUrl('index'))
            ->assertOk();

        $student = $this->createUser(UserRole::Student);

        $this->assertFalse(Gate::forUser($student)->allows('viewAny', Quiz::class));
        $this->actingAs($student)
            ->get(QuizResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_only_super_admin_can_manage_micro_quizzes(): void
    {
        $quiz = Quiz::query()->sole();
        $superAdmin = $this->createUser(UserRole::SuperAdmin);

        $this->assertTrue(Gate::forUser($superAdmin)->allows('create', Quiz::class));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('update', $quiz));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('delete', $quiz));

        foreach ([UserRole::Researcher, UserRole::Teacher] as $role) {
            $user = $this->createUser($role);

            $this->assertFalse(Gate::forUser($user)->allows('create', Quiz::class));
            $this->assertFalse(Gate::forUser($user)->allows('update', $quiz));
            $this->assertFalse(Gate::forUser($user)->allows('delete', $quiz));
        }

        $this->actingAs($this->createUser(UserRole::Teacher));

        Livewire::test(ManageQuizzes::class)
            ->assertActionHidden('create')
            ->assertActionHidden(TestAction::make('edit')->table($quiz))
            ->assertActionHidden(TestAction::make('delete')->table($quiz));
    }

    public function test_filament_quiz_changes_are_visible_through_the_student_api(): void
    {
        $quiz = Quiz::query()->sole();
        $otherMapping = HeritageGeometryMapping::create([
            'heritage_object_id' => $quiz->heritageGeometryMapping->heritage_object_id,
            'geometry_shape_id' => GeometryShape::create(['name' => 'Kerucut'])->id,
            'semantics' => 'didekati sebagai',
        ]);
        $this->actingAs($this->createUser(UserRole::SuperAdmin));

        $this->callFilamentAction(
            Livewire::test(ManageQuizzes::class),
            'create',
            [
                'heritage_geometry_mapping_id' => $quiz->heritage_geometry_mapping_id,
                'title' => 'Kuis Pemetaan Baru',
            ],
        )->assertHasNoActionErrors();

        $created = Quiz::query()->where('title', 'Kuis Pemetaan Baru')->sole();

        $this->callFilamentAction(
            Livewire::test(ManageQuizzes::class),
            TestAction::make('edit')->table($quiz),
            [
                'heritage_geometry_mapping_id' => $otherMapping->id,
                'title' => 'Stupa dan Geometri',
            ],
        )->assertHasNoActionErrors();

        $response = $this->getJson("/api/v1/quizzes/{$quiz->id}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Stupa dan Geometri')
            ->assertJsonPath('data.heritage_geometry_mapping.id', $otherMapping->id)
            ->assertJsonPath('data.heritage_geometry_mapping.heritage_object.name', 'Stupa')
            ->assertJsonPath('data.heritage_geometry_mapping.geometry_shape.name', 'Kerucut')
            ->assertJsonPath(
                'data.heritage_geometry_mapping.semantics',
                'didekati sebagai',
            );

        $this->assertStringNotContainsString('is_correct', $response->getContent());
        $this->assertStringNotContainsString('feedback', $response->getContent());

        Livewire::test(ManageQuizzes::class)
            ->callAction(TestAction::make('delete')->table($created))
            ->assertHasNoActionErrors();

        $this->getJson("/api/v1/quizzes/{$created->id}")->assertNotFound();
    }
}
