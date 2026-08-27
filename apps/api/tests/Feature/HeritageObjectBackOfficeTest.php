<?php

namespace Tests\Feature;

use App\Domain\Heritage\Models\HeritageObject;
use App\Domain\Heritage\Models\HeritageSite;
use App\Domain\Identity\Enums\UserRole;
use App\Filament\Resources\HeritageObjects\HeritageObjectResource;
use App\Filament\Resources\HeritageObjects\Pages\ManageHeritageObjects;
use Database\Seeders\BorobudurSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class HeritageObjectBackOfficeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3', ['url' => 'http://storage.test/vr-geonusa-dev']);
        $this->seed(BorobudurSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_internal_roles_can_view_heritage_objects_but_students_cannot(): void
    {
        foreach ([UserRole::SuperAdmin, UserRole::Researcher, UserRole::Teacher] as $role) {
            $user = $this->createUser($role);

            $this->assertTrue(Gate::forUser($user)->allows('viewAny', HeritageObject::class));
        }

        $this->actingAs($this->createUser(UserRole::Teacher))
            ->get(HeritageObjectResource::getUrl('index'))
            ->assertOk();

        $student = $this->createUser(UserRole::Student);

        $this->assertFalse(Gate::forUser($student)->allows('viewAny', HeritageObject::class));
        $this->actingAs($student)
            ->get(HeritageObjectResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_only_super_admin_can_manage_heritage_objects(): void
    {
        $heritageObject = HeritageObject::query()->sole();
        $superAdmin = $this->createUser(UserRole::SuperAdmin);

        $this->assertTrue(Gate::forUser($superAdmin)->allows('create', HeritageObject::class));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('update', $heritageObject));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('delete', $heritageObject));

        foreach ([UserRole::Researcher, UserRole::Teacher] as $role) {
            $user = $this->createUser($role);

            $this->assertFalse(Gate::forUser($user)->allows('create', HeritageObject::class));
            $this->assertFalse(Gate::forUser($user)->allows('update', $heritageObject));
            $this->assertFalse(Gate::forUser($user)->allows('delete', $heritageObject));
        }

        $this->actingAs($this->createUser(UserRole::Teacher));

        Livewire::test(ManageHeritageObjects::class)
            ->assertActionHidden('create')
            ->assertActionHidden(TestAction::make('edit')->table($heritageObject))
            ->assertActionHidden(TestAction::make('delete')->table($heritageObject));
    }

    public function test_super_admin_crud_is_visible_through_the_learning_material_api(): void
    {
        $site = HeritageSite::query()->sole();
        $stupa = HeritageObject::query()->sole();
        $this->actingAs($this->createUser(UserRole::SuperAdmin));

        $component = Livewire::test(ManageHeritageObjects::class);

        $this->callFilamentAction($component, 'create', [
            'heritage_site_id' => $site->id,
            'name' => 'Stupa',
            'description' => 'Objek warisan tambahan.',
        ])->assertHasNoActionErrors();

        $created = HeritageObject::query()->where('slug', 'stupa-2')->sole();

        $this->callFilamentAction(
            Livewire::test(ManageHeritageObjects::class),
            TestAction::make('edit')->table($stupa),
            [
                'heritage_site_id' => $site->id,
                'name' => 'Stupa Utama',
                'description' => 'Deskripsi objek warisan dari Filament.',
            ],
        )->assertHasNoActionErrors();

        $this->getJson("/api/v1/heritage-objects/{$stupa->id}/learning-material")
            ->assertOk()
            ->assertJsonPath('data.heritage_object.name', 'Stupa Utama')
            ->assertJsonPath(
                'data.heritage_object.description',
                'Deskripsi objek warisan dari Filament.',
            );

        Livewire::test(ManageHeritageObjects::class)
            ->callAction(TestAction::make('delete')->table($created))
            ->assertHasNoActionErrors();

        $this->assertDatabaseMissing('heritage_objects', ['id' => $created->id]);
    }
}
