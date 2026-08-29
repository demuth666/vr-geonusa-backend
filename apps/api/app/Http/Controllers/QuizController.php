<?php

namespace App\Http\Controllers;

use App\Domain\Learning\Models\Quiz;
use App\Http\Resources\QuizResource;

class QuizController extends Controller
{
    public function show(int $id): QuizResource
    {
        return QuizResource::make(
            Quiz::query()
                ->with([
                    'heritageGeometryMapping.heritageObject',
                    'heritageGeometryMapping.geometryShape',
                    'questions.options',
                ])
                ->findOrFail($id),
        );
    }
}
