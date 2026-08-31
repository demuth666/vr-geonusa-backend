<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\Quiz;
use App\Filament\Resources\Questions\Pages\ManageQuestions;
use App\Filament\Resources\Questions\QuestionResource;
use Database\Seeders\BorobudurSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class MicroQuizBackOfficeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3', ['url' => 'http://storage.test/vr-geonusa-dev']);
        $this->seed(BorobudurSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_internal_roles_can_view_questions_but_only_super_admin_can_manage_them(): void
    {
        $question = Question::query()->sole();

        foreach ([UserRole::SuperAdmin, UserRole::Researcher, UserRole::Teacher] as $role) {
            $this->assertTrue(Gate::forUser($this->createUser($role))->allows('viewAny', Question::class));
        }

        $superAdmin = $this->createUser(UserRole::SuperAdmin);
        $this->assertTrue(Gate::forUser($superAdmin)->allows('create', Question::class));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('update', $question));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('delete', $question));

        foreach ([UserRole::Researcher, UserRole::Teacher] as $role) {
            $user = $this->createUser($role);

            $this->assertFalse(Gate::forUser($user)->allows('create', Question::class));
            $this->assertFalse(Gate::forUser($user)->allows('update', $question));
            $this->assertFalse(Gate::forUser($user)->allows('delete', $question));
        }

        $this->actingAs($this->createUser(UserRole::Teacher));

        $this->get(QuestionResource::getUrl('index'))->assertOk();
        Livewire::test(ManageQuestions::class)
            ->assertActionHidden('create')
            ->assertActionHidden(TestAction::make('edit')->table($question))
            ->assertActionHidden(TestAction::make('delete')->table($question));

        $student = $this->createUser(UserRole::Student);
        $this->assertFalse(Gate::forUser($student)->allows('viewAny', Question::class));
        $this->actingAs($student)
            ->get(QuestionResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_question_form_rejects_invalid_option_sets(): void
    {
        $quiz = Quiz::query()->sole();
        $this->actingAs($this->createUser(UserRole::SuperAdmin));

        $this->submitQuestionForm($quiz, 2, [[true, 'Only option']])
            ->assertHasActionErrors(['options' => 'min']);

        $this->submitQuestionForm($quiz, 2, [[false, 'One'], [false, 'Two']])
            ->assertHasActionErrors(['options']);

        $this->submitQuestionForm($quiz, 2, [[true, 'One'], [true, 'Two']])
            ->assertHasActionErrors(['options']);

        $this->assertDatabaseCount('questions', 1);
    }

    public function test_filament_question_changes_are_visible_through_quiz_and_feedback_apis(): void
    {
        $quiz = Quiz::query()->sole();
        $this->actingAs($this->createUser(UserRole::SuperAdmin));

        $this->submitQuestionForm(
            $quiz,
            2,
            [[false, 'Persegi'], [true, 'Lingkaran']],
        )
            ->assertHasNoActionErrors();

        $question = Question::query()->with('options')->where('position', 2)->sole();
        $this->assertSame([1, 2], $question->options->pluck('position')->all());
        $this->assertSame(1, $question->options->where('is_correct', true)->count());

        $this->callFilamentAction(
            Livewire::test(ManageQuestions::class),
            TestAction::make('edit')->table($question),
            function (array $state): array {
                $optionKeys = array_keys($state['options']);
                $state['options'][$optionKeys[0]]['is_correct'] = true;
                $state['options'][$optionKeys[0]]['feedback'] = 'Tepat dari Filament.';
                $state['options'][$optionKeys[1]]['is_correct'] = false;

                return [...$state, 'prompt' => 'Pertanyaan dari Filament diperbarui'];
            },
        )->assertHasNoActionErrors();

        $question->refresh()->load('options');
        $correctOption = $question->options->firstWhere('is_correct', true);

        $response = $this->getJson("/api/v1/quizzes/{$quiz->id}")
            ->assertOk()
            ->assertJsonPath('data.questions.1.prompt', 'Pertanyaan dari Filament diperbarui')
            ->assertJsonPath('data.questions.1.options.0.text', 'Persegi')
            ->assertJsonMissingPath('data.questions.1.options.0.is_correct')
            ->assertJsonMissingPath('data.questions.1.options.0.feedback');

        $this->assertStringNotContainsString('is_correct', $response->getContent());

        $this->submitQuestionForm(
            $quiz,
            3,
            [[true, 'Sementara'], [false, 'Hapus']],
        )->assertHasNoActionErrors();
        $temporaryQuestion = Question::query()->where('position', 3)->sole();

        Livewire::test(ManageQuestions::class)
            ->callAction(TestAction::make('delete')->table($temporaryQuestion))
            ->assertHasNoActionErrors();

        $this->assertDatabaseMissing('questions', ['id' => $temporaryQuestion->id]);
        $this->assertDatabaseMissing('question_options', [
            'question_id' => $temporaryQuestion->id,
        ]);

        [$student, $session, $writeToken] = $this->createLearningSession(
            'filament-question',
            'student',
            exploration: true,
        );
        Sanctum::actingAs($student);

        $attemptId = $this->postJson(
            "/api/v1/learning-sessions/{$session->id}/quiz-attempts",
            ['quiz_id' => $quiz->id],
            ['X-Session-Write-Token' => $writeToken],
        )->assertCreated()->json('data.id');

        $this->putJson(
            "/api/v1/quiz-attempts/{$attemptId}/answers/{$question->id}",
            ['selected_option_id' => $correctOption->id],
            ['X-Session-Write-Token' => $writeToken],
        )
            ->assertOk()
            ->assertJsonPath('data.is_correct', true)
            ->assertJsonPath('data.feedback', 'Tepat dari Filament.');
    }

    /**
     * @param  array<int, array{bool, string}>  $options
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function questionFormState(
        array $state,
        Quiz $quiz,
        int $position,
        array $options,
    ): array {
        $firstOptionKey = array_key_first($state['options']);

        return [
            'quiz_id' => $quiz->id,
            'prompt' => 'Pertanyaan dari Filament',
            'position' => $position,
            'options' => collect($options)->mapWithKeys(
                fn (array $option, int $index): array => [($index === 0
                    ? $firstOptionKey
                    : (string) Str::uuid()) => [
                        'text' => $option[1],
                        'feedback' => "Umpan balik {$option[1]}",
                        'is_correct' => $option[0],
                    ]],
            )->all(),
        ];
    }

    /** @param array<int, array{bool, string}> $options */
    private function submitQuestionForm(Quiz $quiz, int $position, array $options): Testable
    {
        return $this->callFilamentAction(
            Livewire::test(ManageQuestions::class),
            'create',
            fn (array $state): array => $this->questionFormState(
                $state,
                $quiz,
                $position,
                $options,
            ),
        );
    }
}
