<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveAssessmentAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'selected_option_id' => ['required', 'integer'],
            'attempt_id' => ['prohibited'],
            'item_id' => ['prohibited'],
            'is_correct' => ['prohibited'],
            'score' => ['prohibited'],
        ];
    }
}
