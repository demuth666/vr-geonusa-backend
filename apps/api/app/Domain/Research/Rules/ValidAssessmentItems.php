<?php

namespace App\Domain\Research\Rules;

use App\Domain\Research\Enums\AssessmentType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidAssessmentItems implements ValidationRule
{
    public function __construct(private readonly ?AssessmentType $type) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->type || ! is_array($value)) {
            return;
        }

        foreach (array_values($value) as $index => $item) {
            $correctOptions = collect($item['options'] ?? [])
                ->filter(fn (array $option): bool => filter_var(
                    $option['is_correct'] ?? false,
                    FILTER_VALIDATE_BOOL,
                ))
                ->count();

            if ($this->type === AssessmentType::SelfEfficacy && $correctOptions !== 0) {
                $fail('Self-efficacy item :position cannot have a correct answer.')
                    ->translate(['position' => $index + 1]);
            }

            if ($this->type !== AssessmentType::SelfEfficacy && $correctOptions !== 1) {
                $fail('Assessment item :position must have exactly one correct answer.')
                    ->translate(['position' => $index + 1]);
            }
        }
    }
}
