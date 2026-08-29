<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartQuizAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        $rules = [
            'quiz_id' => ['required', 'integer', 'exists:quizzes,id'],
        ];

        foreach (array_diff(array_keys($this->all()), array_keys($rules)) as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }
}
