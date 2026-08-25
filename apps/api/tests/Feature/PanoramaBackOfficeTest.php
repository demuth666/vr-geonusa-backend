<?php

namespace Tests\Feature;

use App\Domain\Heritage\Models\PanoramaLink;
use App\Domain\Heritage\Models\PanoramaNode;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\PanoramaLinks\Pages\ManagePanoramaLinks;
use App\Filament\Resources\PanoramaLinks\PanoramaLinkResource;
use App\Filament\Resources\PanoramaNodes\Pages\ManagePanoramaNodes;
use App\Filament\Resources\PanoramaNodes\PanoramaNodeResource;
use Database\Seeders\BorobudurSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class PanoramaBackOfficeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BorobudurSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_internal_roles_can_view_panorama_resources(): void
    {
        foreach ([UserRole::SuperAdmin, UserRole::Researcher, UserRole::Teacher] as $role) {
            $user = $this->createUser($role);

            $this->assertTrue(Gate::forUser($user)->allows('viewAny', PanoramaNode::class));
            $this->assertTrue(Gate::forUser($user)->allows('viewAny', PanoramaLink::class));
        }

        $this->actingAs($this->createUser(UserRole::Teacher))
            ->get(PanoramaNodeResource::getUrl('index'))
            ->assertOk();
        $this->get(PanoramaLinkResource::getUrl('index'))
            ->assertOk();
    }

    public function test_students_cannot_access_panorama_resources(): void
    {
        $student = $this->createUser(UserRole::Student);

        $this->assertFalse(Gate::forUser($student)->allows('viewAny', PanoramaNode::class));
        $this->assertFalse(Gate::forUser($student)->allows('viewAny', PanoramaLink::class));
        $this->actingAs($student)
            ->get(PanoramaNodeResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_only_super_admin_can_manage_panorama_graph(): void
    {
        $node = PanoramaNode::query()->firstOrFail();
        $link = PanoramaLink::query()->firstOrFail();
        $superAdmin = $this->createUser(UserRole::SuperAdmin);

        foreach ([PanoramaNode::class, PanoramaLink::class] as $model) {
            $this->assertTrue(Gate::forUser($superAdmin)->allows('create', $model));
        }
        foreach ([$node, $link] as $record) {
            $this->assertTrue(Gate::forUser($superAdmin)->allows('update', $record));
            $this->assertTrue(Gate::forUser($superAdmin)->allows('delete', $record));
        }

        foreach ([UserRole::Researcher, UserRole::Teacher] as $role) {
            $user = $this->createUser($role);

            $this->assertFalse(Gate::forUser($user)->allows('create', PanoramaNode::class));
            $this->assertFalse(Gate::forUser($user)->allows('create', PanoramaLink::class));
            $this->assertFalse(Gate::forUser($user)->allows('update', $node));
            $this->assertFalse(Gate::forUser($user)->allows('delete', $link));
        }

        $this->actingAs($this->createUser(UserRole::Teacher));

        Livewire::test(ManagePanoramaNodes::class)
            ->assertActionHidden('create')
            ->assertActionHidden(TestAction::make('edit')->table($node))
            ->assertActionHidden(TestAction::make('delete')->table($node));
        Livewire::test(ManagePanoramaLinks::class)
            ->assertActionHidden('create')
            ->assertActionHidden(TestAction::make('edit')->table($link))
            ->assertActionHidden(TestAction::make('delete')->table($link));
    }

    public function test_filament_graph_changes_are_visible_through_the_rest_api(): void
    {
        $source = PanoramaNode::query()->where('slug', 'pelataran-timur')->firstOrFail();
        $target = PanoramaNode::query()->where('slug', 'pelataran-barat')->firstOrFail();

        $this->actingAs($this->createUser(UserRole::SuperAdmin));

        Livewire::test(ManagePanoramaNodes::class)
            ->callAction(TestAction::make('edit')->table($source), [
                'heritage_area_id' => $source->heritage_area_id,
                'name' => 'Pelataran Timur Borobudur',
                'slug' => $source->slug,
                'panorama_url' => $source->panorama_url,
            ])
            ->assertHasNoActionErrors();

        Livewire::test(ManagePanoramaLinks::class)
            ->callAction('create', [
                'source_node_id' => $source->id,
                'target_node_id' => $target->id,
                'label' => 'Menuju Pelataran Barat',
                'yaw' => 135,
                'pitch' => 2.5,
            ])
            ->assertHasNoActionErrors();

        $this->getJson("/api/v1/panorama-nodes/{$source->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Pelataran Timur Borobudur')
            ->assertJsonFragment([
                'label' => 'Menuju Pelataran Barat',
                'yaw' => 135,
                'pitch' => 2.5,
            ]);
    }

    public function test_panorama_link_form_rejects_duplicate_edges(): void
    {
        $link = PanoramaLink::query()->firstOrFail();

        $this->actingAs($this->createUser(UserRole::SuperAdmin));

        Livewire::test(ManagePanoramaLinks::class)
            ->callAction('create', [
                'source_node_id' => $link->source_node_id,
                'target_node_id' => $link->target_node_id,
                'label' => 'Duplicate edge',
                'yaw' => $link->yaw,
                'pitch' => $link->pitch,
            ])
            ->assertHasActionErrors(['target_node_id' => 'unique']);

        $this->assertDatabaseCount('panorama_links', 4);
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
