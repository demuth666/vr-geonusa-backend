<?php

namespace Tests\Feature;

use App\Domain\Learning\Models\Quiz;
use Database\Seeders\BorobudurSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MicroQuizTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BorobudurSeeder::class);
    }

    public function test_student_can_fetch_a_mapping_based_micro_quiz_without_answer_data(): void
    {
        $quiz = Quiz::query()->sole();

        $this->getJson("/api/v1/quizzes/{$quiz->id}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Stupa dan Setengah Bola')
            ->assertJsonPath(
                'data.heritage_geometry_mapping.semantics',
                'didekati sebagai',
            )
            ->assertJsonPath('data.heritage_geometry_mapping.heritage_object.name', 'Stupa')
            ->assertJsonPath('data.heritage_geometry_mapping.geometry_shape.name', 'Setengah Bola')
            ->assertJsonCount(1, 'data.questions')
            ->assertJsonPath('data.questions.0.position', 1)
            ->assertJsonPath(
                'data.questions.0.prompt',
                'Bangun ruang apa yang paling mendekati bentuk stupa Borobudur?',
            )
            ->assertJsonCount(3, 'data.questions.0.options')
            ->assertJsonPath('data.questions.0.options.0.text', 'Setengah Bola')
            ->assertJsonPath('data.questions.0.options.1.text', 'Kubus')
            ->assertJsonPath('data.questions.0.options.2.text', 'Balok')
            ->assertJsonMissingPath('data.questions.0.options.0.is_correct')
            ->assertJsonMissingPath('data.questions.0.options.0.feedback');
    }

    public function test_missing_micro_quiz_uses_the_standard_not_found_error(): void
    {
        $this->getJson('/api/v1/quizzes/999999')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND')
            ->assertJsonPath('error.message', 'Resource not found.');
    }
}
