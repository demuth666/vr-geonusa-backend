<?php

namespace Tests\Feature;

use App\Domain\Heritage\Models\HeritageArea;
use App\Domain\Heritage\Models\HeritageSite;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\HeritageAreas\HeritageAreaResource;
use App\Filament\Resources\HeritageAreas\Pages\ManageHeritageAreas;
use App\Filament\Resources\HeritageSites\HeritageSiteResource;
use App\Filament\Resources\HeritageSites\Pages\ManageHeritageSites;
use Database\Seeders\BorobudurSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class HeritageBackOfficeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BorobudurSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_internal_roles_can_view_heritage_resources(): void
    {
        foreach ([UserRole::SuperAdmin, UserRole::Researcher, UserRole::Teacher] as $role) {
            $user = $this->createUser($role);

            $this->assertTrue(Gate::forUser($user)->allows('viewAny', HeritageSite::class));
            $this->assertTrue(Gate::forUser($user)->allows('viewAny', HeritageArea::class));
        }

        $this->actingAs($this->createUser(UserRole::Teacher))
            ->get(HeritageSiteResource::getUrl('index'))
            ->assertOk();
        $this->get(HeritageAreaResource::getUrl('index'))
            ->assertOk();
    }

    public function test_students_cannot_access_heritage_resources(): void
    {
        $student = $this->createUser(UserRole::Student);

        $this->assertFalse(Gate::forUser($student)->allows('viewAny', HeritageSite::class));
        $this->assertFalse(Gate::forUser($student)->allows('viewAny', HeritageArea::class));
        $this->actingAs($student)
            ->get(HeritageSiteResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_only_super_admin_can_manage_borobudur_heritage_content(): void
    {
        $site = HeritageSite::query()->firstOrFail();
        $area = HeritageArea::query()->firstOrFail();
        $superAdmin = $this->createUser(UserRole::SuperAdmin);

        $this->assertFalse(Gate::forUser($superAdmin)->allows('create', HeritageSite::class));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('update', $site));
        $this->assertFalse(Gate::forUser($superAdmin)->allows('delete', $site));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('create', HeritageArea::class));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('update', $area));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('delete', $area));

        foreach ([UserRole::Researcher, UserRole::Teacher] as $role) {
            $user = $this->createUser($role);

            $this->assertFalse(Gate::forUser($user)->allows('update', $site));
            $this->assertFalse(Gate::forUser($user)->allows('create', HeritageArea::class));
            $this->assertFalse(Gate::forUser($user)->allows('update', $area));
            $this->assertFalse(Gate::forUser($user)->allows('delete', $area));
        }

        $this->actingAs($this->createUser(UserRole::Teacher));

        Livewire::test(ManageHeritageSites::class)
            ->assertActionHidden(TestAction::make('edit')->table($site));
        Livewire::test(ManageHeritageAreas::class)
            ->assertActionHidden('create')
            ->assertActionHidden(TestAction::make('edit')->table($area))
            ->assertActionHidden(TestAction::make('delete')->table($area));
    }

    public function test_filament_edits_are_visible_through_the_rest_api(): void
    {
        $site = HeritageSite::query()->firstOrFail();

        $this->actingAs($this->createUser(UserRole::SuperAdmin));

        Livewire::test(ManageHeritageSites::class)
            ->callAction(TestAction::make('edit')->table($site), [
                'name' => $site->name,
                'slug' => $site->slug,
                'description' => 'Deskripsi Borobudur dari Filament.',
                'cover_image_url' => $site->cover_image_url,
            ])
            ->assertHasNoActionErrors();

        Livewire::test(ManageHeritageAreas::class)
            ->callAction('create', [
                'heritage_site_id' => $site->id,
                'name' => 'Pelataran Selatan',
                'slug' => 'pelataran-selatan',
                'description' => 'Area baru dari Filament.',
            ])
            ->assertHasNoActionErrors();

        $this->getJson('/api/v1/heritage-sites/borobudur')
            ->assertOk()
            ->assertJsonPath('data.description', 'Deskripsi Borobudur dari Filament.');
        $this->getJson('/api/v1/heritage-sites/borobudur/areas')
            ->assertOk()
            ->assertJsonFragment([
                'name' => 'Pelataran Selatan',
                'slug' => 'pelataran-selatan',
                'description' => 'Area baru dari Filament.',
            ]);
    }

    private function createUser(UserRole $role): User
    {
        return User::create([
            'email' => "{$role->value}-".str()->random(8).'@example.test',
            'password' => 'password',
            'role' => $role,
        ]);
    }
}
