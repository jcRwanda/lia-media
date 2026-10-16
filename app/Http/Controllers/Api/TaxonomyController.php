<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Cohort;
use App\Models\Event;
use App\Models\Project;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Projects, cohorts, events and categories share one small CRUD controller. */
class TaxonomyController extends Controller
{
    private const TYPES = ['projects' => Project::class, 'cohorts' => Cohort::class, 'events' => Event::class, 'categories' => Category::class];

    public function __construct(private AuditLogger $audit) {}

    private function model(string $type): string
    {
        return self::TYPES[$type] ?? abort(404);
    }

    private function rules(string $type, ?Model $m = null): array
    {
        $id = $m?->id;

        return match ($type) {
            'projects' => ['name' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string'], 'status' => ['nullable', Rule::in(['active', 'completed', 'archived'])], 'starts_on' => ['nullable', 'date'], 'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on']],
            'cohorts' => ['name' => ['required', 'string', 'max:160'], 'code' => ['required', 'string', 'max:40', Rule::unique('cohorts', 'code')->ignore($id)], 'year' => ['nullable', 'integer', 'min:2000', 'max:2100'], 'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')]],
            'events' => ['title' => ['required', 'string', 'max:190'], 'occurred_on' => ['nullable', 'date'], 'ends_on' => ['nullable', 'date'], 'location' => ['nullable', 'string', 'max:190'], 'description' => ['nullable', 'string'], 'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')], 'cohort_id' => ['nullable', 'integer', Rule::exists('cohorts', 'id')]],
            'categories' => ['name' => ['required', 'string', 'max:120'], 'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')]],
        };
    }

    private function uniqueSlug(string $class, string $base, ?int $ignore = null): string
    {
        $slug = Str::limit(Str::slug($base) ?: 'item', 170, '');
        $try = $slug;
        for ($i = 2; $class::query()->where('slug', $try)->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists(); $i++) {
            $try = "{$slug}-{$i}";
        }

        return $try;
    }

    public function index(Request $request, string $type): JsonResponse
    {
        $class = $this->model($type);
        $rows = $class::query()->withCount('assets')->orderByDesc('id')->get();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request, string $type): JsonResponse
    {
        $class = $this->model($type);
        $data = $request->validate($this->rules($type));
        if ($type !== 'cohorts') {
            $data['slug'] = $this->uniqueSlug($class, $data['name'] ?? $data['title'].(isset($data['occurred_on']) ? ' '.substr($data['occurred_on'], 0, 4) : ''));
        }
        if ($type === 'projects') {
            $data['created_by'] = $request->user()->id;
        }
        $m = $class::query()->create($data);
        $this->audit->log("{$type}.created", $m);

        return response()->json(['data' => $m], 201);
    }

    public function update(Request $request, string $type, int $id): JsonResponse
    {
        $class = $this->model($type);
        $m = $class::query()->findOrFail($id);
        $m->update($request->validate($this->rules($type, $m)));
        $this->audit->log("{$type}.updated", $m);

        return response()->json(['data' => $m]);
    }

    public function destroy(string $type, int $id): JsonResponse
    {
        $class = $this->model($type);
        $m = $class::query()->withCount('assets')->findOrFail($id);
        if ($m->assets_count > 0) {
            throw ValidationException::withMessages(['id' => "Still used by {$m->assets_count} asset(s). Move them first."]);
        }
        $m->delete();
        $this->audit->log("{$type}.deleted", $m);

        return response()->json(['ok' => true]);
    }
}
