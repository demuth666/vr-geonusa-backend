<?php

namespace App\Domain\Learning\Models;

use App\Domain\Heritage\Models\HeritageObject;
use App\Domain\Heritage\Models\PanoramaNode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityEvent extends Model
{
    public const PANORAMA_VISITED = 'panorama_visited';

    public const MATERIAL_VIEWED = 'material_viewed';

    public $timestamps = false;

    protected $fillable = [
        'learning_session_id',
        'type',
        'panorama_node_id',
        'heritage_object_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
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

    public function heritageObject(): BelongsTo
    {
        return $this->belongsTo(HeritageObject::class);
    }
}
