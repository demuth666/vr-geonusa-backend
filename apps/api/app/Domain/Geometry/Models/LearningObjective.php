<?php

namespace App\Domain\Geometry\Models;

use Illuminate\Database\Eloquent\Model;

class LearningObjective extends Model
{
    protected $fillable = [
        'heritage_geometry_mapping_id',
        'title',
        'material_content',
        'position',
    ];
}
