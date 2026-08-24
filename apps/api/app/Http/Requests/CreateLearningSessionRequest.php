<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateLearningSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'respondent_code' => ['required', 'string', 'regex:/^RSP-[A-Z0-9]{6}$/'],
            'phase' => ['prohibited'],
            'research_participant_id' => ['prohibited'],
            'write_token' => ['prohibited'],
            'write_token_hash' => ['prohibited'],
        ];
    }
}
