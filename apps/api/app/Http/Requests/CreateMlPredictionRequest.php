<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class CreateMlPredictionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $rules = [
            'image' => [
                'required',
                File::image()
                    ->types(['jpg', 'jpeg', 'png', 'webp'])
                    ->max('5mb')
                    ->dimensions(Rule::dimensions()->maxWidth(4096)->maxHeight(4096)),
            ],
            'panorama_node_id' => ['required', 'integer', 'exists:panorama_nodes,id'],
            'camera_yaw' => ['required', 'numeric', 'between:-180,180'],
            'camera_pitch' => ['required', 'numeric', 'between:-90,90'],
            'camera_fov' => ['required', 'numeric', 'gt:0', 'lte:180'],
        ];

        foreach (array_diff(array_keys($this->all()), array_keys($rules)) as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }
}
