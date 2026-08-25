<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitAssessmentAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'answers' => ['prohibited'],
            'score' => ['prohibited'],
            'is_correct' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
