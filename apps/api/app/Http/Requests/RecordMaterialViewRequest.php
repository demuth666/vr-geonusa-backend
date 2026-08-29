<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordMaterialViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'heritage_object_id' => ['prohibited'],
            'learning_session_id' => ['prohibited'],
            'phase' => ['prohibited'],
            'viewed_at' => ['prohibited'],
        ];
    }
}
