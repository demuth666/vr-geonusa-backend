<?php

namespace App\Domain\Learning\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidQuestionOptions implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        $correctOptions = collect($value)
            ->filter(fn (array $option): bool => filter_var(
                $option['is_correct'] ?? false,
                FILTER_VALIDATE_BOOL,
            ))
            ->count();

        if ($correctOptions !== 1) {
            $fail('A Micro Quiz question must have exactly one correct answer.');
        }
    }
}
