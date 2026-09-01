<?php

namespace App\Domain\MachineLearning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MlDetection extends Model
{
    protected $fillable = ['class_key', 'confidence', 'bounding_box'];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'bounding_box' => 'array',
        ];
    }

    public function inferenceRun(): BelongsTo
    {
        return $this->belongsTo(MlInferenceRun::class, 'ml_inference_run_id');
    }
}
