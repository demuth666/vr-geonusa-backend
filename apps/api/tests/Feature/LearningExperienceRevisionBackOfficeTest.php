<?php

namespace Tests\Feature;

use App\Domain\Heritage\Models\HeritageObject;
use App\Domain\Heritage\Models\PanoramaNode;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Learning\Models\LearningExperienceRevision;
use App\Domain\Learning\Models\Quiz;
use App\Domain\Research\Models\ResearchStudy;
use App\Filament\Resources\LearningExperienceRevisions\LearningExperienceRevisionResource;
use App\Filament\Resources\LearningExperienceRevisions\Pages\ManageLearningExperienceRevisions;
use Database\Seeders\BorobudurSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class LearningExperienceRevisionBackOfficeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BorobudurSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_super_admin_can_publish_an_immutable_revision(): void
    {
        $study = ResearchStudy::create(['name' => 'Revision Study']);
        $admin = $this->createUser(UserRole::SuperAdmin);
        $this->actingAs($admin);

        $this->assertTrue(Gate::forUser($admin)->allows('create', LearningExperienceRevision::class));
        $this->callFilamentAction(
            Livewire::test(ManageLearningExperienceRevisions::class),
            'create',
            [
                'research_study_id' => $study->id,
                'required_panorama_node_ids' => [PanoramaNode::query()->firstOrFail()->id],
                'required_heritage_object_ids' => [HeritageObject::query()->sole()->id],
                'required_quiz_ids' => [Quiz::query()->sole()->id],
            ],
        )->assertHasNoActionErrors();

        $draft = LearningExperienceRevision::query()->sole();
        $this->assertSame('draft', $draft->status);

        Livewire::test(ManageLearningExperienceRevisions::class)
            ->callAction(TestAction::make('publish')->table($draft))
            ->assertHasNoActionErrors();

        $published = $draft->fresh();
        $this->assertSame('published', $published->status);
        $this->assertFalse(Gate::forUser($admin)->allows('update', $published));
        Livewire::test(ManageLearningExperienceRevisions::class)
            ->assertActionHidden(TestAction::make('edit')->table($published))
            ->assertActionHidden(TestAction::make('delete')->table($published));

        $this->expectException(QueryException::class);
        $published->requiredPanoramaNodes()->detach();
    }

    public function test_only_internal_staff_can_view_learning_experience_revisions(): void
    {
        $teacher = $this->createUser(UserRole::Teacher);
        $student = $this->createUser(UserRole::Student);
        $draft = LearningExperienceRevision::create([
            'research_study_id' => ResearchStudy::create(['name' => 'Draft Study'])->id,
            'version' => 1,
        ]);

        $this->assertTrue(Gate::forUser($teacher)->allows('viewAny', LearningExperienceRevision::class));
        $this->assertFalse(Gate::forUser($student)->allows('viewAny', LearningExperienceRevision::class));
        $this->actingAs($teacher)
            ->get(LearningExperienceRevisionResource::getUrl('index'))
            ->assertOk();
        Livewire::test(ManageLearningExperienceRevisions::class)
            ->assertActionHidden(TestAction::make('publish')->table($draft));
        $this->actingAs($student)
            ->get(LearningExperienceRevisionResource::getUrl('index'))
            ->assertForbidden();
    }
}
