<?php

namespace Database\Seeders;

use App\Domain\Research\Enums\AssessmentType;
use App\Domain\Research\Models\AssessmentInstrument;
use App\Domain\Research\Models\AssessmentItem;
use App\Domain\Research\Models\AssessmentOption;
use App\Domain\Research\Models\ResearchStudy;
use Illuminate\Database\Seeder;

class AssessmentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $study = ResearchStudy::firstOrCreate([
            'name' => 'VR GeoNusa Borobudur Study',
        ]);
        $geometryItems = [
            [
                'prompt' => 'Bangun ruang apakah yang paling mendekati bentuk stupa utama Borobudur?',
                'options' => [
                    ['text' => 'Setengah bola', 'is_correct' => true],
                    ['text' => 'Kubus', 'is_correct' => false],
                    ['text' => 'Balok', 'is_correct' => false],
                ],
            ],
            [
                'prompt' => 'Manakah unsur yang merupakan ciri bangun ruang?',
                'options' => [
                    ['text' => 'Memiliki volume', 'is_correct' => true],
                    ['text' => 'Hanya memiliki panjang', 'is_correct' => false],
                    ['text' => 'Tidak memiliki sisi', 'is_correct' => false],
                ],
            ],
        ];

        foreach ([
            [AssessmentType::Pretest, 'Pretest Geometri Borobudur', $geometryItems],
            [AssessmentType::Posttest, 'Posttest Geometri Borobudur', $geometryItems],
            [
                AssessmentType::SelfEfficacy,
                'Kuesioner Efikasi Diri Geometri Borobudur',
                [
                    [
                        'prompt' => 'Saya yakin dapat mengenali bangun ruang pada struktur Borobudur.',
                        'options' => [
                            ['text' => 'Tidak yakin', 'is_correct' => false],
                            ['text' => 'Cukup yakin', 'is_correct' => false],
                            ['text' => 'Sangat yakin', 'is_correct' => false],
                        ],
                    ],
                    [
                        'prompt' => 'Saya yakin dapat menjelaskan alasan bentuk geometri yang digunakan.',
                        'options' => [
                            ['text' => 'Tidak yakin', 'is_correct' => false],
                            ['text' => 'Cukup yakin', 'is_correct' => false],
                            ['text' => 'Sangat yakin', 'is_correct' => false],
                        ],
                    ],
                ],
            ],
        ] as [$type, $title, $items]) {
            $instrument = AssessmentInstrument::updateOrCreate(
                [
                    'research_study_id' => $study->id,
                    'type' => $type->value,
                ],
                ['title' => $title],
            );

            foreach ($items as $itemPosition => $itemData) {
                $item = AssessmentItem::updateOrCreate(
                    [
                        'assessment_instrument_id' => $instrument->id,
                        'position' => $itemPosition + 1,
                    ],
                    ['prompt' => $itemData['prompt']],
                );

                foreach ($itemData['options'] as $optionPosition => $optionData) {
                    AssessmentOption::updateOrCreate(
                        [
                            'assessment_item_id' => $item->id,
                            'position' => $optionPosition + 1,
                        ],
                        $optionData,
                    );
                }
            }
        }
    }
}
