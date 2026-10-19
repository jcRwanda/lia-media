<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\Category;
use App\Models\Cohort;
use App\Models\Event;
use App\Models\Project;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;

/** Small option lists for selects and filters. */
class LookupController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'projects' => Project::query()->orderBy('name')->get(['id', 'name', 'slug', 'status']),
            'cohorts' => Cohort::query()->orderByDesc('year')->orderBy('name')->get(['id', 'name', 'code', 'year', 'project_id']),
            'events' => Event::query()->orderByDesc('occurred_on')->get(['id', 'title', 'slug', 'occurred_on', 'project_id', 'cohort_id']),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name', 'slug', 'parent_id']),
            'albums' => Album::query()->orderBy('title')->get(['id', 'uuid', 'title', 'kind', 'is_default']),
            'tags' => Tag::query()->orderBy('name')->limit(500)->get(['id', 'name', 'slug']),
        ]);
    }
}
