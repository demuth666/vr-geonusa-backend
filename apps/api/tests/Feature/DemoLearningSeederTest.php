<?php

namespace Tests\Feature;

use App\Domain\Geometry\Models\GeometryShape;
use Database\Seeders\AssessmentSeeder;
use Database\Seeders\BorobudurSeeder;
use Database\Seeders\DemoLearningSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoLearningSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_learning_seeder_is_idempotent_and_publishes_a_revision(): void
    {
        $this->seed(UserSeeder::class);
        $this->seed(BorobudurSeeder::class);
        $this->seed(AssessmentSeeder::class);

        $this->seed(DemoLearningSeeder::class);
        $this->seed(DemoLearningSeeder::class);

        $this->assertDatabaseCount('research_participants', 1);
        $this->assertDatabaseCount('learning_experience_revisions', 1);
        $this->assertDatabaseHas('learning_experience_revisions', [
            'status' => 'published',
        ]);
        $this->assertDatabaseCount('ml_class_mappings', 1);
        $this->assertDatabaseHas('ml_class_mappings', [
            'class_key' => 'setengah_bola',
            'geometry_shape_id' => GeometryShape::query()->where('slug', 'setengah-bola')->sole()->id,
        ]);
    }
}
