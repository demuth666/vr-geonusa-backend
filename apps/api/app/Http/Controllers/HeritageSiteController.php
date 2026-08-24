<?php

namespace App\Http\Controllers;

use App\Domain\Heritage\Models\HeritageSite;
use App\Http\Resources\HeritageAreaResource;
use App\Http\Resources\HeritageSiteResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HeritageSiteController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return HeritageSiteResource::collection(
            HeritageSite::query()->orderBy('name')->get(),
        );
    }

    public function show(string $slug): HeritageSiteResource
    {
        return HeritageSiteResource::make(
            HeritageSite::query()->where('slug', $slug)->firstOrFail(),
        );
    }

    public function areas(string $slug): AnonymousResourceCollection
    {
        $site = HeritageSite::query()->where('slug', $slug)->firstOrFail();

        return HeritageAreaResource::collection(
            $site->areas()->with('panoramaNodes')->get(),
        );
    }
}
