<?php

namespace App\Domain\Heritage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PanoramaLink extends Model
{
    protected $fillable = [
        'source_node_id',
        'target_node_id',
        'yaw',
        'pitch',
        'label',
    ];

    protected function casts(): array
    {
        return [
            'yaw' => 'float',
            'pitch' => 'float',
        ];
    }

    public function sourceNode(): BelongsTo
    {
        return $this->belongsTo(PanoramaNode::class, 'source_node_id');
    }

    public function targetNode(): BelongsTo
    {
        return $this->belongsTo(PanoramaNode::class, 'target_node_id');
    }
}
