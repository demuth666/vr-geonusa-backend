<?php

namespace Tests\Feature;

use App\Domain\Heritage\Models\PanoramaNode;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\MachineLearning\Enums\MlInferenceFailureReason;
use App\Domain\MachineLearning\Enums\MlInferenceStatus;
use App\Domain\MachineLearning\Models\MlDetection;
use App\Domain\MachineLearning\Models\MlInferenceRun;
use App\Domain\MachineLearning\Models\MlModel;
use App\Domain\MachineLearning\Models\MlModelVersion;
use App\Filament\Resources\MlInferenceRuns\MlInferenceRunResource;
use App\Filament\Resources\MlInferenceRuns\Pages\ManageMlInferenceRuns;
use App\Filament\Resources\MlModels\MlModelResource;
use App\Filament\Resources\MlModels\Pages\ManageMlModels;
use App\Filament\Resources\MlModelVersions\MlModelVersionResource;
use App\Filament\Resources\MlModelVersions\Pages\ManageMlModelVersions;
use Database\Seeders\BorobudurSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class MlBackOfficeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BorobudurSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_internal_roles_can_view_ml_resources_but_students_cannot(): void
    {
        foreach ([UserRole::SuperAdmin, UserRole::Researcher, UserRole::Teacher] as $role) {
            $user = $this->createUser($role);

            $this->assertTrue(Gate::forUser($user)->allows('viewAny', MlModel::class));
            $this->assertTrue(Gate::forUser($user)->allows('viewAny', MlModelVersion::class));
            $this->assertTrue(Gate::forUser($user)->allows('viewAny', MlInferenceRun::class));
        }

        $this->actingAs($this->createUser(UserRole::Teacher));
        $this->get(MlModelResource::getUrl('index'))->assertOk();
        $this->get(MlModelVersionResource::getUrl('index'))->assertOk();
        $this->get(MlInferenceRunResource::getUrl('index'))->assertOk();

        $student = $this->createUser(UserRole::Student);

        $this->assertFalse(Gate::forUser($student)->allows('viewAny', MlModel::class));
        $this->assertFalse(Gate::forUser($student)->allows('viewAny', MlModelVersion::class));
        $this->assertFalse(Gate::forUser($student)->allows('viewAny', MlInferenceRun::class));

        $this->actingAs($student);
        $this->get(MlModelResource::getUrl('index'))->assertForbidden();
        $this->get(MlModelVersionResource::getUrl('index'))->assertForbidden();
        $this->get(MlInferenceRunResource::getUrl('index'))->assertForbidden();
    }

    public function test_only_super_admin_can_manage_ml_models_and_versions(): void
    {
        $model = MlModel::create(['key' => 'geometry-detector', 'name' => 'Geometry Detector']);
        $version = MlModelVersion::create(['ml_model_id' => $model->id, 'version' => 'v1']);

        $superAdmin = $this->createUser(UserRole::SuperAdmin);
        $researcher = $this->createUser(UserRole::Researcher);
        $teacher = $this->createUser(UserRole::Teacher);

        foreach ([$researcher, $teacher] as $user) {
            $this->assertFalse(Gate::forUser($user)->allows('create', MlModel::class));
            $this->assertFalse(Gate::forUser($user)->allows('update', $model));
            $this->assertFalse(Gate::forUser($user)->allows('delete', $model));
            $this->assertFalse(Gate::forUser($user)->allows('create', MlModelVersion::class));
            $this->assertFalse(Gate::forUser($user)->allows('update', $version));
            $this->assertFalse(Gate::forUser($user)->allows('delete', $version));
        }

        $this->assertTrue(Gate::forUser($superAdmin)->allows('create', MlModel::class));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('update', $model));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('delete', $model));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('create', MlModelVersion::class));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('update', $version));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('delete', $version));

        $this->actingAs($researcher);
        Livewire::test(ManageMlModels::class)
            ->assertActionHidden('create')
            ->assertActionHidden(TestAction::make('edit')->table($model))
            ->assertActionHidden(TestAction::make('delete')->table($model));
        Livewire::test(ManageMlModelVersions::class)
            ->assertActionHidden('create')
            ->assertActionHidden(TestAction::make('edit')->table($version))
            ->assertActionHidden(TestAction::make('delete')->table($version));

        $this->actingAs($superAdmin);
        Livewire::test(ManageMlModels::class)
            ->assertActionVisible('create')
            ->assertActionVisible(TestAction::make('edit')->table($model))
            ->assertActionVisible(TestAction::make('delete')->table($model));
        Livewire::test(ManageMlModelVersions::class)
            ->assertActionVisible('create')
            ->assertActionVisible(TestAction::make('edit')->table($version))
            ->assertActionVisible(TestAction::make('delete')->table($version));
    }

    public function test_ml_model_version_form_enforces_relationship_and_the_unique_version_constraint(): void
    {
        $model = MlModel::create(['key' => 'geometry-detector', 'name' => 'Geometry Detector']);
        $otherModel = MlModel::create(['key' => 'other-detector', 'name' => 'Other Detector']);
        MlModelVersion::create(['ml_model_id' => $model->id, 'version' => 'v1']);

        $this->actingAs($this->createUser(UserRole::SuperAdmin));

        $this->callFilamentAction(
            Livewire::test(ManageMlModelVersions::class),
            'create',
            ['ml_model_id' => null, 'version' => 'v2'],
        )->assertHasActionErrors(['ml_model_id' => 'required']);

        $this->callFilamentAction(
            Livewire::test(ManageMlModelVersions::class),
            'create',
            ['ml_model_id' => $model->id, 'version' => 'v1'],
        )->assertHasActionErrors(['version' => 'unique']);

        $this->callFilamentAction(
            Livewire::test(ManageMlModelVersions::class),
            'create',
            ['ml_model_id' => $otherModel->id, 'version' => 'v1'],
        )->assertHasNoActionErrors();

        $this->assertDatabaseHas('ml_model_versions', [
            'ml_model_id' => $otherModel->id,
            'version' => 'v1',
        ]);
    }

    public function test_inference_runs_and_detections_are_system_generated_read_only_records(): void
    {
        [$run, $detection] = $this->seedInferenceRun();
        $failedRun = MlInferenceRun::create([
            'learning_session_id' => $run->learning_session_id,
            'panorama_node_id' => $run->panorama_node_id,
            'ml_model_version_id' => null,
            'camera_yaw' => 12.5,
            'camera_pitch' => -5.0,
            'camera_fov' => 75.0,
            'inference_ms' => null,
            'total_latency_ms' => 42,
            'status' => MlInferenceStatus::Failed,
            'failure_reason' => MlInferenceFailureReason::Timeout,
        ]);

        $superAdmin = $this->createUser(UserRole::SuperAdmin);

        $this->assertFalse(Gate::forUser($superAdmin)->allows('create', MlInferenceRun::class));
        $this->assertFalse(Gate::forUser($superAdmin)->allows('update', $run));
        $this->assertFalse(Gate::forUser($superAdmin)->allows('delete', $run));

        $this->actingAs($superAdmin);

        Livewire::test(ManageMlInferenceRuns::class)
            ->assertCanSeeTableRecords([$run, $failedRun])
            ->assertActionDoesNotExist('create')
            ->assertActionDoesNotExist(TestAction::make('edit')->table($run))
            ->assertActionDoesNotExist(TestAction::make('delete')->table($run))
            ->assertSee($detection->class_key)
            ->assertSee('0.9500');
    }

    /** @return array{MlInferenceRun, MlDetection} */
    private function seedInferenceRun(): array
    {
        [, $session] = $this->createLearningSession('ml-backoffice', 'a', exploration: true);
        $node = PanoramaNode::query()->firstOrFail();
        $model = MlModel::create(['key' => 'geometry-detector', 'name' => 'Geometry Detector']);
        $version = MlModelVersion::create(['ml_model_id' => $model->id, 'version' => 'v1']);

        $run = MlInferenceRun::create([
            'learning_session_id' => $session->id,
            'panorama_node_id' => $node->id,
            'ml_model_version_id' => $version->id,
            'camera_yaw' => 45.5,
            'camera_pitch' => -10.0,
            'camera_fov' => 90.0,
            'inference_ms' => 10,
            'total_latency_ms' => 25,
            'status' => MlInferenceStatus::Succeeded,
            'failure_reason' => null,
        ]);

        $detection = $run->detections()->create([
            'class_key' => 'stupa',
            'confidence' => 0.95,
            'bounding_box' => [10, 20, 100, 120],
        ]);

        return [$run, $detection];
    }
}
