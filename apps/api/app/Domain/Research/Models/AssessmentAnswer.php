<?php

namespace App\Domain\Research\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentAnswer extends Model
{
    protected $fillable = [
        'assessment_attempt_id',
        'assessment_item_id',
        'selected_option_id',
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

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(AssessmentAttempt::class, 'assessment_attempt_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AssessmentItem::class, 'assessment_item_id');
    }

    public function selectedOption(): BelongsTo
    {
        return $this->belongsTo(AssessmentOption::class, 'selected_option_id');
    }
}
