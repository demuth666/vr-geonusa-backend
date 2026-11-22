<?php

namespace Tests\Feature;

use App\Domain\Geometry\Models\GeometryShape;
use App\Domain\Heritage\Models\PanoramaNode;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\MachineLearning\Models\MlClassMapping;
use App\Domain\MachineLearning\Models\MlModel;
use App\Domain\MachineLearning\Models\MlModelVersion;
use App\Filament\Resources\MlClassMappings\MlClassMappingResource;
use App\Filament\Resources\MlClassMappings\Pages\ManageMlClassMappings;
use Database\Seeders\BorobudurSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class MlClassMappingBackOfficeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BorobudurSeeder::class);
        config(['services.ml.url' => 'http://ml.test']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_internal_roles_can_view_class_mappings_but_students_cannot(): void
    {
        [$version] = $this->seedClassMapping();
        $mapping = $version->classMappings()->firstOrFail();

        foreach ([UserRole::SuperAdmin, UserRole::Researcher, UserRole::Teacher] as $role) {
            $user = $this->createUser($role);

            $this->assertTrue(Gate::forUser($user)->allows('viewAny', MlClassMapping::class));
            $this->assertTrue(Gate::forUser($user)->allows('view', $mapping));
        }

        $this->actingAs($this->createUser(UserRole::Teacher));
        $this->get(MlClassMappingResource::getUrl('index'))
            ->assertOk()
            ->assertSee('setengah_bola');

        $student = $this->createUser(UserRole::Student);
        $this->assertFalse(Gate::forUser($student)->allows('viewAny', MlClassMapping::class));

        $this->actingAs($student);
        $this->get(MlClassMappingResource::getUrl('index'))->assertForbidden();
    }

    public function test_only_super_admin_can_manage_class_mappings(): void
    {
        [, $mapping] = $this->seedClassMapping();
        $researcher = $this->createUser(UserRole::Researcher);
        $superAdmin = $this->createUser(UserRole::SuperAdmin);

        $this->assertFalse(Gate::forUser($researcher)->allows('create', MlClassMapping::class));
        $this->assertFalse(Gate::forUser($researcher)->allows('update', $mapping));
        $this->assertFalse(Gate::forUser($researcher)->allows('delete', $mapping));

        $this->assertTrue(Gate::forUser($superAdmin)->allows('create', MlClassMapping::class));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('update', $mapping));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('delete', $mapping));

        $this->actingAs($researcher);
        Livewire::test(ManageMlClassMappings::class)
            ->assertCanSeeTableRecords([$mapping])
            ->assertActionHidden('create')
            ->assertActionHidden(TestAction::make('edit')->table($mapping))
            ->assertActionHidden(TestAction::make('delete')->table($mapping));

        $this->actingAs($superAdmin);
        Livewire::test(ManageMlClassMappings::class)
            ->assertActionVisible('create')
            ->assertActionVisible(TestAction::make('edit')->table($mapping))
            ->assertActionVisible(TestAction::make('delete')->table($mapping));
    }

    public function test_a_class_key_is_unique_per_model_version_but_reusable_across_versions(): void
    {
        $model = MlModel::create(['key' => 'geometry-detector', 'name' => 'Geometry Detector']);
        $first = MlModelVersion::create(['ml_model_id' => $model->id, 'version' => 'v1']);
        $second = MlModelVersion::create(['ml_model_id' => $model->id, 'version' => 'v2']);
        $shape = $this->shape('setengah-bola');

        $this->actingAs($this->createUser(UserRole::SuperAdmin));

        $this->createMapping($first->id, 'setengah_bola', $shape->id)
            ->assertHasNoActionErrors();

        $this->createMapping($first->id, 'setengah_bola', $shape->id)
            ->assertHasActionErrors(['class_key' => 'unique']);

        $this->createMapping($second->id, 'setengah_bola', $shape->id)
            ->assertHasNoActionErrors();

        $this->assertDatabaseCount('ml_class_mappings', 2);
    }

    public function test_a_mapping_authored_in_filament_resolves_a_detection(): void
    {
        [$user, $session, $writeToken] = $this->createLearningSession('ml-mapping', 'a', exploration: true);
        $version = $this->geometryDetectorVersion('dummy-v1');
        $node = PanoramaNode::query()->where('slug', 'stupa-induk')->sole();

        $this->actingAs($this->createUser(UserRole::SuperAdmin));
        $this->createMapping($version->id, 'setengah_bola', $this->shape('setengah-bola')->id)
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('ml_class_mappings', [
            'ml_model_version_id' => $version->id,
            'class_key' => 'setengah_bola',
        ]);
        $this->assertSame(
            $version->id,
            MlModelVersion::query()->where('version', 'dummy-v1')->sole()->id,
        );

        Http::fake(['ml.test/*' => Http::response([
            'model_version' => 'dummy-v1',
            'inference_ms' => 10,
            'detections' => [[
                'class' => 'setengah_bola',
                'confidence' => 0.95,
                'bounding_box' => [10, 20, 100, 120],
            ]],
        ])]);

        Sanctum::actingAs($user);

        $this->post(
            "/api/v1/learning-sessions/{$session->id}/ml-predictions",
            [
                'image' => $this->image(),
                'panorama_node_id' => $node->id,
                'camera_yaw' => 45.5,
                'camera_pitch' => -10,
                'camera_fov' => 90,
            ],
            [
                'Accept' => 'application/json',
                'X-Session-Write-Token' => $writeToken,
            ],
        )->assertCreated()
            ->assertJsonPath('data.detections.0.geometry_shape.slug', 'setengah-bola')
            ->assertJsonPath('data.detections.0.heritage_mappings.0.heritage_object.slug', 'stupa');
    }

    /** @return array{MlModelVersion, MlClassMapping} */
    private function seedClassMapping(string $version = 'v1'): array
    {
        $modelVersion = $this->geometryDetectorVersion($version);
        $mapping = MlClassMapping::query()->create([
            'ml_model_version_id' => $modelVersion->id,
            'class_key' => 'setengah_bola',
            'geometry_shape_id' => $this->shape('setengah-bola')->id,
        ]);

        return [$modelVersion, $mapping];
    }

    private function geometryDetectorVersion(string $version): MlModelVersion
    {
        $model = MlModel::query()->firstOrCreate(
            ['key' => 'geometry-detector'],
            ['name' => 'Geometry Detector'],
        );

        return MlModelVersion::query()->firstOrCreate([
            'ml_model_id' => $model->id,
            'version' => $version,
        ]);
    }

    private function createMapping(int $versionId, string $classKey, int $shapeId): Testable
    {
        return $this->callFilamentAction(
            Livewire::test(ManageMlClassMappings::class),
            'create',
            [
                'ml_model_version_id' => $versionId,
                'class_key' => $classKey,
                'geometry_shape_id' => $shapeId,
            ],
        );
    }

    private function shape(string $slug): GeometryShape
    {
        return GeometryShape::query()->where('slug', $slug)->sole();
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'viewport.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
        );
    }
}
