<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveQuizAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        $rules = [
            'selected_option_id' => ['required', 'integer'],
        ];

        foreach (array_diff(array_keys($this->all()), array_keys($rules)) as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }
}
