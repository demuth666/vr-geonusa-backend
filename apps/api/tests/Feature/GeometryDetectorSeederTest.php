<?php

namespace Tests\Feature;

use App\Domain\Geometry\Models\GeometryShape;
use App\Domain\MachineLearning\Models\MlModelVersion;
use Database\Seeders\BorobudurSeeder;
use Database\Seeders\GeometryDetectorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeometryDetectorSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_maps_every_detector_class_to_its_geometry_shape_idempotently(): void
    {
        $this->seed(BorobudurSeeder::class);

        $this->seed(GeometryDetectorSeeder::class);
        $this->seed(GeometryDetectorSeeder::class);

        $version = MlModelVersion::query()
            ->where('version', GeometryDetectorSeeder::MODEL_VERSION)
            ->sole();

        $this->assertSame(8, $version->classMappings()->count());
        $this->assertDatabaseCount('ml_class_mappings', 8);

        foreach (GeometryDetectorSeeder::CLASS_MAPPINGS as $classKey => $shapeSlug) {
            $this->assertDatabaseHas('ml_class_mappings', [
                'ml_model_version_id' => $version->id,
                'class_key' => $classKey,
                'geometry_shape_id' => GeometryShape::query()
                    ->where('slug', $shapeSlug)
                    ->sole()
                    ->id,
            ]);
        }
    }
}
