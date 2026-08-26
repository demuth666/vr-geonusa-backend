<?php

namespace Tests\Feature;

use App\Domain\Heritage\Models\HeritageArea;
use App\Domain\Heritage\Models\HeritageSite;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PanoramaBackOfficeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3', ['url' => 'http://storage.test/vr-geonusa-dev']);
        $this->seed(BorobudurSeeder::class);
        PanoramaNode::query()->each(fn (PanoramaNode $node) => Storage::disk('s3')->put(
            $node->panorama_url,
            'panorama',
        ));
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

        $this->callFilamentAction(
            Livewire::test(ManagePanoramaNodes::class),
            TestAction::make('edit')->table($source),
            [
                'heritage_area_id' => $source->heritage_area_id,
                'name' => 'Pelataran Timur Borobudur',
                'panorama_url' => [$source->panorama_url],
            ],
        )
            ->assertHasNoActionErrors();

        $this->callFilamentAction(
            Livewire::test(ManagePanoramaLinks::class),
            'create',
            [
                'source_node_id' => $source->id,
                'target_node_id' => $target->id,
                'label' => 'Menuju Pelataran Barat',
                'yaw' => 135,
                'pitch' => 2.5,
            ],
        )
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

        $this->callFilamentAction(
            Livewire::test(ManagePanoramaLinks::class),
            'create',
            [
                'source_node_id' => $link->source_node_id,
                'target_node_id' => $link->target_node_id,
                'label' => 'Duplicate edge',
                'yaw' => $link->yaw,
                'pitch' => $link->pitch,
            ],
        )
            ->assertHasActionErrors(['target_node_id' => 'unique']);

        $this->assertDatabaseCount('panorama_links', 4);
    }

    public function test_filament_generates_unique_slugs_and_uploads_panorama_to_storage(): void
    {
        $area = HeritageArea::query()->firstOrFail();
        $this->actingAs($this->createUser(UserRole::SuperAdmin));

        foreach (['panorama.jpg', 'panorama-duplicate.jpg'] as $file) {
            $this->callFilamentAction(
                Livewire::test(ManagePanoramaNodes::class),
                'create',
                [
                    'heritage_area_id' => $area->id,
                    'name' => 'Panorama Upload',
                    'panorama_url' => UploadedFile::fake()->create($file, 256, 'image/jpeg'),
                ],
            )->assertHasNoActionErrors();
        }

        $node = PanoramaNode::query()->where('slug', 'panorama-upload')->sole();
        $duplicate = PanoramaNode::query()->where('slug', 'panorama-upload-2')->sole();
        $path = $node->panorama_url;

        $this->assertStringStartsWith('panoramas/', $path);
        $this->assertFalse(filter_var($path, FILTER_VALIDATE_URL));
        Storage::disk('s3')->assertExists($path);
        Storage::disk('s3')->assertExists($duplicate->panorama_url);

        $this->getJson("/api/v1/panorama-nodes/{$node->id}")
            ->assertOk()
            ->assertJsonPath('data.panorama_url', "http://storage.test/vr-geonusa-dev/{$path}");
    }

    public function test_panorama_upload_rejects_invalid_and_oversized_files(): void
    {
        $area = HeritageArea::query()->firstOrFail();
        $this->actingAs($this->createUser(UserRole::SuperAdmin));

        $this->callFilamentAction(
            Livewire::test(ManagePanoramaNodes::class),
            'create',
            [
                'heritage_area_id' => $area->id,
                'name' => 'Invalid Panorama',
                'panorama_url' => UploadedFile::fake()->create('panorama.pdf', 100, 'application/pdf'),
            ],
        )->assertHasActionErrors(['panorama_url']);

        $this->callFilamentAction(
            Livewire::test(ManagePanoramaNodes::class),
            'create',
            [
                'heritage_area_id' => $area->id,
                'name' => 'Oversized Panorama',
                'panorama_url' => UploadedFile::fake()->create(
                    'panorama.jpg',
                    (12 * 1024) + 1,
                    'image/jpeg',
                ),
            ],
        )->assertHasActionErrors(['panorama_url']);

        $this->assertDatabaseMissing('panorama_nodes', ['slug' => 'invalid-panorama']);
        $this->assertDatabaseMissing('panorama_nodes', ['slug' => 'oversized-panorama']);
    }

    public function test_replacing_and_deleting_panorama_records_removes_stored_objects(): void
    {
        $node = PanoramaNode::query()->firstOrFail();
        $oldPath = $node->panorama_url;
        $this->actingAs($this->createUser(UserRole::SuperAdmin));

        $this->callFilamentAction(
            Livewire::test(ManagePanoramaNodes::class),
            TestAction::make('edit')->table($node),
            [
                'heritage_area_id' => $node->heritage_area_id,
                'name' => $node->name,
                'panorama_url' => [
                    UploadedFile::fake()->create('replacement.jpg', 256, 'image/jpeg'),
                ],
            ],
        )->assertHasNoActionErrors();

        $newPath = $node->refresh()->panorama_url;
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('s3')->assertMissing($oldPath);
        Storage::disk('s3')->assertExists($newPath);

        Livewire::test(ManagePanoramaNodes::class)
            ->callAction(TestAction::make('delete')->table($node));

        $this->assertDatabaseMissing('panorama_nodes', ['id' => $node->id]);
        Storage::disk('s3')->assertMissing($newPath);
    }

    public function test_deleting_heritage_parent_removes_all_panorama_objects(): void
    {
        $site = HeritageSite::query()->firstOrFail();
        $paths = PanoramaNode::query()->pluck('panorama_url');

        $site->delete();

        foreach ($paths as $path) {
            Storage::disk('s3')->assertMissing($path);
        }
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
