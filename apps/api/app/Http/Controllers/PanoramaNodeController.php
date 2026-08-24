<?php

namespace App\Http\Controllers;

use App\Domain\Heritage\Models\PanoramaNode;
use App\Http\Resources\PanoramaNodeResource;

class PanoramaNodeController extends Controller
{
    public function show(int $id): PanoramaNodeResource
    {
        $node = PanoramaNode::query()
            ->with(['heritageArea.heritageSite', 'outgoingLinks.targetNode'])
            ->findOrFail($id);

        return PanoramaNodeResource::make($node);
    }
}
