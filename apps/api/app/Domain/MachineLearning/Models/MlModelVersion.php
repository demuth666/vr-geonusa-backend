<?php

namespace App\Domain\MachineLearning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MlModelVersion extends Model
{
    protected $fillable = ['ml_model_id', 'version'];

    public function model(): BelongsTo
    {
        return $this->belongsTo(MlModel::class, 'ml_model_id');
    }

    public function inferenceRuns(): HasMany
    {
        return $this->hasMany(MlInferenceRun::class);
    }

    public function classMappings(): HasMany
    {
        return $this->hasMany(MlClassMapping::class);
    }
}
