<?php

namespace Tests\Feature;

use App\Domain\Heritage\Models\PanoramaNode;
use Database\Seeders\BorobudurSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HeritageContentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3', ['url' => 'http://127.0.0.1:9000/vr-geonusa-dev']);
        $this->seed(BorobudurSeeder::class);
    }

    public function test_heritage_sites_are_publicly_available_by_list_and_slug(): void
    {
        $this->getJson('/api/v1/heritage-sites')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Candi Borobudur')
            ->assertJsonPath('data.0.slug', 'borobudur')
            ->assertJsonPath(
                'data.0.cover_image_url',
                'http://127.0.0.1:9000/vr-geonusa-dev/heritage/borobudur-cover.jpg',
            );

        $this->getJson('/api/v1/heritage-sites/borobudur')
            ->assertOk()
            ->assertJsonPath('data.name', 'Candi Borobudur')
            ->assertJsonPath('data.slug', 'borobudur');
    }

    public function test_borobudur_area_contains_three_panorama_nodes(): void
    {
        $this->getJson('/api/v1/heritage-sites/borobudur/areas')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Pelataran Utama')
            ->assertJsonCount(3, 'data.0.panorama_nodes')
            ->assertJsonPath('data.0.panorama_nodes.1.name', 'Stupa Induk')
            ->assertJsonPath(
                'data.0.panorama_nodes.1.panorama_url',
                'http://127.0.0.1:9000/vr-geonusa-dev/panoramas/borobudur/stupa-induk.jpg',
            );
    }

    public function test_panorama_node_exposes_outgoing_graph_links(): void
    {
        $node = PanoramaNode::query()->where('slug', 'stupa-induk')->firstOrFail();

        $this->getJson("/api/v1/panorama-nodes/{$node->id}")
            ->assertOk()
            ->assertJsonPath('data.area.heritage_site.slug', 'borobudur')
            ->assertJsonCount(2, 'data.links')
            ->assertJsonPath('data.links.0.target.name', 'Pelataran Timur')
            ->assertJsonPath('data.links.1.target.name', 'Pelataran Barat')
            ->assertJsonMissingPath('data.next')
            ->assertJsonMissingPath('data.previous');
    }

    public function test_missing_heritage_resources_use_standard_not_found_errors(): void
    {
        $this->getJson('/api/v1/heritage-sites/missing')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->getJson('/api/v1/panorama-nodes/999999')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_borobudur_seed_is_idempotent(): void
    {
        $this->seed(BorobudurSeeder::class);

        $this->assertDatabaseCount('heritage_sites', 1);
        $this->assertDatabaseCount('heritage_areas', 1);
        $this->assertDatabaseCount('panorama_nodes', 3);
        $this->assertDatabaseCount('panorama_links', 4);
        $this->assertDatabaseCount('heritage_objects', 1);
        $this->assertDatabaseCount('panorama_object_annotations', 1);
        $this->assertDatabaseCount('geometry_shapes', 1);
        $this->assertDatabaseCount('heritage_geometry_mappings', 1);
        $this->assertDatabaseCount('learning_objectives', 1);
        $this->assertDatabaseCount('quizzes', 1);
        $this->assertDatabaseCount('questions', 1);
        $this->assertDatabaseCount('question_options', 3);
    }
}
