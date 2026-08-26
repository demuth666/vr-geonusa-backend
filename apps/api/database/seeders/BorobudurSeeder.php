<?php

namespace Database\Seeders;

use App\Domain\Geometry\Models\GeometryShape;
use App\Domain\Geometry\Models\HeritageGeometryMapping;
use App\Domain\Geometry\Models\LearningObjective;
use App\Domain\Heritage\Models\HeritageArea;
use App\Domain\Heritage\Models\HeritageObject;
use App\Domain\Heritage\Models\HeritageSite;
use App\Domain\Heritage\Models\PanoramaLink;
use App\Domain\Heritage\Models\PanoramaNode;
use App\Domain\Heritage\Models\PanoramaObjectAnnotation;
use Illuminate\Database\Seeder;

class BorobudurSeeder extends Seeder
{
    public function run(): void
    {
        $site = HeritageSite::updateOrCreate(
            ['slug' => 'borobudur'],
            [
                'name' => 'Candi Borobudur',
                'description' => 'Situs warisan budaya untuk pembelajaran geometri berbasis panorama.',
                'cover_image_url' => 'heritage/borobudur-cover.jpg',
            ],
        );

        $area = HeritageArea::updateOrCreate(
            ['heritage_site_id' => $site->id, 'slug' => 'pelataran-utama'],
            [
                'name' => 'Pelataran Utama',
                'description' => 'Area eksplorasi panorama di sekitar stupa utama Borobudur.',
            ],
        );

        $nodes = collect([
            'pelataran-timur' => 'Pelataran Timur',
            'stupa-induk' => 'Stupa Induk',
            'pelataran-barat' => 'Pelataran Barat',
        ])->mapWithKeys(function (string $name, string $slug) use ($area) {
            $node = PanoramaNode::updateOrCreate(
                ['heritage_area_id' => $area->id, 'slug' => $slug],
                [
                    'name' => $name,
                    'panorama_url' => "panoramas/borobudur/{$slug}.jpg",
                ],
            );

            return [$slug => $node];
        });

        foreach ([
            ['stupa-induk', 'pelataran-timur', -90, 0, 'Menuju Pelataran Timur'],
            ['stupa-induk', 'pelataran-barat', 90, 0, 'Menuju Pelataran Barat'],
            ['pelataran-timur', 'stupa-induk', 90, 0, 'Kembali ke Stupa Induk'],
            ['pelataran-barat', 'stupa-induk', -90, 0, 'Kembali ke Stupa Induk'],
        ] as [$source, $target, $yaw, $pitch, $label]) {
            PanoramaLink::updateOrCreate(
                [
                    'source_node_id' => $nodes[$source]->id,
                    'target_node_id' => $nodes[$target]->id,
                ],
                compact('yaw', 'pitch', 'label'),
            );
        }

        $stupa = HeritageObject::updateOrCreate(
            ['heritage_site_id' => $site->id, 'slug' => 'stupa'],
            [
                'name' => 'Stupa',
                'description' => 'Elemen arsitektur Buddhis pada Candi Borobudur.',
            ],
        );

        PanoramaObjectAnnotation::updateOrCreate([
            'panorama_node_id' => $nodes['stupa-induk']->id,
            'heritage_object_id' => $stupa->id,
        ]);

        $halfSphere = GeometryShape::updateOrCreate(
            ['slug' => 'setengah-bola'],
            [
                'name' => 'Setengah Bola',
                'description' => 'Bagian dari bola yang dibatasi oleh sebuah lingkaran besar.',
            ],
        );

        $mapping = HeritageGeometryMapping::updateOrCreate(
            [
                'heritage_object_id' => $stupa->id,
                'geometry_shape_id' => $halfSphere->id,
            ],
            ['semantics' => 'didekati sebagai'],
        );

        LearningObjective::updateOrCreate(
            ['heritage_geometry_mapping_id' => $mapping->id, 'position' => 1],
            [
                'title' => 'Mengenali unsur setengah bola',
                'material_content' => 'Stupa dapat didekati sebagai setengah bola untuk mempelajari permukaan lengkung dan alas lingkarannya.',
            ],
        );
    }
}
