<?php

namespace App\Domain\MachineLearning\Models;

use App\Domain\Heritage\Models\PanoramaNode;
use App\Domain\Learning\Models\LearningSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MlInferenceRun extends Model
{
    protected $fillable = [
        'learning_session_id',
        'panorama_node_id',
        'ml_model_version_id',
        'camera_yaw',
        'camera_pitch',
        'camera_fov',
        'inference_ms',
        'total_latency_ms',
    ];

    protected function casts(): array
    {
        return [
            'camera_yaw' => 'float',
            'camera_pitch' => 'float',
            'camera_fov' => 'float',
        ];
    }

    public function learningSession(): BelongsTo
    {
        return $this->belongsTo(LearningSession::class);
    }

    public function panoramaNode(): BelongsTo
    {
        return $this->belongsTo(PanoramaNode::class);
    }

    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(MlModelVersion::class, 'ml_model_version_id');
    }

    public function detections(): HasMany
    {
        return $this->hasMany(MlDetection::class);
    }
}
