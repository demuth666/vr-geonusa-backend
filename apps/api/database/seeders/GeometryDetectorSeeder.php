<?php

namespace Database\Seeders;

use App\Domain\Geometry\Models\GeometryShape;
use App\Domain\MachineLearning\Models\MlClassMapping;
use App\Domain\MachineLearning\Models\MlModel;
use App\Domain\MachineLearning\Models\MlModelVersion;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Maps the served geometry detector's class keys to the seeded geometry shapes.
 *
 * This is real model-version configuration rather than demo data, so it runs explicitly
 * instead of from `DatabaseSeeder`. It requires the shapes seeded by `BorobudurSeeder`
 * and is idempotent: re-running it rewrites the same eight mappings. Its version string
 * must match the ML service's `ML_MODEL_VERSION`.
 */
class GeometryDetectorSeeder extends Seeder
{
    public const MODEL_KEY = 'geometry-detector';

    public const MODEL_VERSION = 'geometry-yolo11s-v0-partial';

    /** @var array<string, string> Detector class key to Geometry Shape slug. */
    public const CLASS_MAPPINGS = [
        'balok' => 'balok',
        'bola' => 'bola',
        'kerucut' => 'kerucut',
        'kubus' => 'kubus',
        'limas_segiempat' => 'limas-segiempat',
        'prisma_segitiga' => 'prisma-segitiga',
        'setengah_bola' => 'setengah-bola',
        'tabung' => 'tabung',
    ];

    public function run(): void
    {
        $model = MlModel::query()->firstOrCreate(
            ['key' => self::MODEL_KEY],
            ['name' => 'Geometry Detector'],
        );
        $version = MlModelVersion::query()->firstOrCreate([
            'ml_model_id' => $model->id,
            'version' => self::MODEL_VERSION,
        ]);

        $shapes = GeometryShape::query()
            ->whereIn('slug', array_values(self::CLASS_MAPPINGS))
            ->get()
            ->keyBy('slug');

        foreach (self::CLASS_MAPPINGS as $classKey => $shapeSlug) {
            $shape = $shapes->get($shapeSlug);

            if ($shape === null) {
                throw new RuntimeException(
                    "Geometry Shape '{$shapeSlug}' is missing; run BorobudurSeeder first.",
                );
            }

            MlClassMapping::query()->updateOrCreate(
                [
                    'ml_model_version_id' => $version->id,
                    'class_key' => $classKey,
                ],
                ['geometry_shape_id' => $shape->id],
            );
        }
    }
}
