<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartAssessmentAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'instrument_id' => ['prohibited'],
            'type' => ['prohibited'],
            'phase' => ['prohibited'],
            'status' => ['prohibited'],
            'score' => ['prohibited'],
        ];
    }
}
