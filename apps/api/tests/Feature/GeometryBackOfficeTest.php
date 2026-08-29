<?php

namespace Tests\Feature;

use App\Domain\Geometry\Models\GeometryShape;
use App\Domain\Geometry\Models\HeritageGeometryMapping;
use App\Domain\Heritage\Models\HeritageObject;
use App\Domain\Identity\Enums\UserRole;
use App\Filament\Resources\GeometryShapes\GeometryShapeResource;
use App\Filament\Resources\GeometryShapes\Pages\ManageGeometryShapes;
use App\Filament\Resources\HeritageGeometryMappings\HeritageGeometryMappingResource;
use App\Filament\Resources\HeritageGeometryMappings\Pages\ManageHeritageGeometryMappings;
use Database\Seeders\BorobudurSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class GeometryBackOfficeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3', ['url' => 'http://storage.test/vr-geonusa-dev']);
        $this->seed(BorobudurSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_internal_roles_can_view_geometry_resources_but_students_cannot(): void
    {
        foreach ([UserRole::SuperAdmin, UserRole::Researcher, UserRole::Teacher] as $role) {
            $user = $this->createUser($role);

            $this->assertTrue(Gate::forUser($user)->allows('viewAny', GeometryShape::class));
            $this->assertTrue(
                Gate::forUser($user)->allows('viewAny', HeritageGeometryMapping::class),
            );
        }

        $this->actingAs($this->createUser(UserRole::Teacher))
            ->get(GeometryShapeResource::getUrl('index'))
            ->assertOk();
        $this->get(HeritageGeometryMappingResource::getUrl('index'))->assertOk();

        $student = $this->createUser(UserRole::Student);

        $this->assertFalse(Gate::forUser($student)->allows('viewAny', GeometryShape::class));
        $this->assertFalse(
            Gate::forUser($student)->allows('viewAny', HeritageGeometryMapping::class),
        );
        $this->actingAs($student)
            ->get(GeometryShapeResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_geometry_authoring_follows_role_boundaries(): void
    {
        $shape = GeometryShape::query()->sole();
        $mapping = HeritageGeometryMapping::query()->sole();
        $superAdmin = $this->createUser(UserRole::SuperAdmin);
        $researcher = $this->createUser(UserRole::Researcher);
        $teacher = $this->createUser(UserRole::Teacher);

        $this->assertTrue(Gate::forUser($superAdmin)->allows('create', GeometryShape::class));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('update', $shape));
        $this->assertTrue(
            Gate::forUser($superAdmin)->allows('create', HeritageGeometryMapping::class),
        );
        $this->assertTrue(Gate::forUser($superAdmin)->allows('update', $mapping));
        $this->assertFalse(Gate::forUser($superAdmin)->allows('delete', $shape));
        $this->assertFalse(Gate::forUser($superAdmin)->allows('delete', $mapping));

        $this->assertFalse(Gate::forUser($researcher)->allows('create', GeometryShape::class));
        $this->assertFalse(Gate::forUser($researcher)->allows('update', $shape));
        $this->assertTrue(
            Gate::forUser($researcher)->allows('create', HeritageGeometryMapping::class),
        );
        $this->assertTrue(Gate::forUser($researcher)->allows('update', $mapping));

        $this->assertFalse(
            Gate::forUser($teacher)->allows('create', HeritageGeometryMapping::class),
        );
        $this->assertFalse(Gate::forUser($teacher)->allows('update', $mapping));

        $this->actingAs($researcher);

        Livewire::test(ManageGeometryShapes::class)
            ->assertActionHidden('create')
            ->assertActionHidden(TestAction::make('edit')->table($shape));
        Livewire::test(ManageHeritageGeometryMappings::class)
            ->assertActionVisible('create')
            ->assertActionVisible(TestAction::make('edit')->table($mapping));
    }

    public function test_filament_geometry_changes_are_visible_through_the_learning_material_api(): void
    {
        $heritageObject = HeritageObject::query()->sole();
        $this->actingAs($this->createUser(UserRole::SuperAdmin));

        $this->callFilamentAction(
            Livewire::test(ManageGeometryShapes::class),
            'create',
            [
                'name' => 'Kerucut',
                'description' => 'Bangun ruang dengan alas lingkaran.',
            ],
        )->assertHasNoActionErrors();

        $shape = GeometryShape::query()->where('slug', 'kerucut')->sole();
        $this->actingAs($this->createUser(UserRole::Researcher));

        $this->callFilamentAction(
            Livewire::test(ManageHeritageGeometryMappings::class),
            'create',
            [
                'heritage_object_id' => $heritageObject->id,
                'geometry_shape_id' => $shape->id,
                'semantics' => 'didekati sebagai',
            ],
        )->assertHasNoActionErrors();

        $this->getJson("/api/v1/heritage-objects/{$heritageObject->id}/learning-material")
            ->assertOk()
            ->assertJsonFragment([
                'semantics' => 'didekati sebagai',
                'name' => 'Kerucut',
                'slug' => 'kerucut',
                'description' => 'Bangun ruang dengan alas lingkaran.',
            ]);

        $this->callFilamentAction(
            Livewire::test(ManageHeritageGeometryMappings::class),
            'create',
            [
                'heritage_object_id' => $heritageObject->id,
                'geometry_shape_id' => $shape->id,
                'semantics' => 'didekati sebagai',
            ],
        )->assertHasActionErrors(['geometry_shape_id' => 'unique']);

        $mapping = HeritageGeometryMapping::query()
            ->where('geometry_shape_id', $shape->id)
            ->sole();

        $this->callFilamentAction(
            Livewire::test(ManageHeritageGeometryMappings::class),
            TestAction::make('edit')->table($mapping),
            [
                'heritage_object_id' => $heritageObject->id,
                'geometry_shape_id' => $shape->id,
                'semantics' => 'adalah',
            ],
        )->assertHasActionErrors(['semantics' => 'in']);
    }
}
