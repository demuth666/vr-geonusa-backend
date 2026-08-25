<?php

namespace App\Domain\Research\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentOption extends Model
{
    protected $fillable = [
        'assessment_item_id',
        'text',
        'position',
        'is_correct',
    ];

    protected $hidden = [
        'is_correct',
    ];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AssessmentItem::class, 'assessment_item_id');
    }
}
