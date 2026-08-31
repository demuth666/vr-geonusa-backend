<?php

namespace App\Domain\Learning\Actions;

use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\QuestionOption;

class PrepareCorrectQuestionOption
{
    public function execute(
        Question $question,
        bool $willBeCorrect,
        ?QuestionOption $currentOption = null,
    ): void {
        if (! $willBeCorrect || $currentOption?->is_correct) {
            return;
        }

        $question->options()
            ->when(
                $currentOption,
                fn ($query) => $query->whereKeyNot($currentOption->id),
            )
            ->update(['is_correct' => false]);
    }
}
