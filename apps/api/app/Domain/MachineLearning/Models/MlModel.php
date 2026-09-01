<?php

namespace App\Domain\MachineLearning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MlModel extends Model
{
    protected $fillable = ['key', 'name'];

    public function versions(): HasMany
    {
        return $this->hasMany(MlModelVersion::class);
    }
}
