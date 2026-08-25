<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordPanoramaVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'panorama_node_id' => ['required', 'integer', 'exists:panorama_nodes,id'],
            'current_panorama_node_id' => ['prohibited'],
            'phase' => ['prohibited'],
            'visited_at' => ['prohibited'],
        ];
    }
}
