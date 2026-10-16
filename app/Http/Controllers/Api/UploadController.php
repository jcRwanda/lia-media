<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UploadSession;
use App\Services\UploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UploadController extends Controller
{
    public function __construct(private UploadService $uploads) {}

    public function index(Request $request): JsonResponse
    {
        $rows = UploadSession::query()->with('asset:id,uuid')->where('user_id', $request->user()->id)
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->latest('id')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn ($s) => $this->uploads->sessionPayload($s))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'mime_type' => ['required', 'string', 'max:120', function ($attr, $value, $fail) {
                if (! UploadService::mimeAllowed($value)) {
                    $fail('This file type is not allowed.');
                }
            }],
            'size_bytes' => ['required', 'integer', 'min:1', 'max:'.config('media.max_file_size')],
            'sha256' => ['required', 'string', 'regex:/^[a-fA-F0-9]{64}$/'],
            'context' => ['nullable', 'array'],
            'context.title' => ['nullable', 'string', 'max:190'],
            'context.project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')],
            'context.cohort_id' => ['nullable', 'integer', Rule::exists('cohorts', 'id')],
            'context.event_id' => ['nullable', 'integer', Rule::exists('events', 'id')],
            'context.category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'context.album_id' => ['nullable', 'integer', Rule::exists('albums', 'id')->whereNull('deleted_at')],
            'context.credit' => ['nullable', 'string', 'max:190'],
            'context.tags' => ['nullable', 'array', 'max:30'],
            'context.tags.*' => ['string', 'max:60'],
            'context.width' => ['nullable', 'integer', 'min:1'],
            'context.height' => ['nullable', 'integer', 'min:1'],
            'context.duration_ms' => ['nullable', 'integer', 'min:0'],
            'context.captured_at' => ['nullable', 'date'],
        ]);
        $res = $this->uploads->initiate($request->user(), $data);

        return response()->json($res, $res['status'] === 'duplicate' ? 200 : 201);
    }

    public function show(Request $request, UploadSession $upload): JsonResponse
    {
        $this->own($request, $upload);

        return response()->json(['upload' => $this->uploads->sessionPayload($upload), 'parts' => $this->uploads->uploadedParts($upload)]);
    }

    public function parts(Request $request, UploadSession $upload): JsonResponse
    {
        $this->own($request, $upload);
        $data = $request->validate(['part_numbers' => ['required', 'array', 'min:1', 'max:100'], 'part_numbers.*' => ['integer', 'min:1']]);

        return response()->json(['urls' => $this->uploads->partUrls($upload, $data['part_numbers'])]);
    }

    public function renew(Request $request, UploadSession $upload): JsonResponse
    {
        $this->own($request, $upload);

        return response()->json(['put' => $this->uploads->renewPut($upload)]);
    }

    public function complete(Request $request, UploadSession $upload): JsonResponse
    {
        $this->own($request, $upload);
        $asset = $this->uploads->complete($upload, $request->user());

        return response()->json(['status' => $upload->fresh()->status, 'asset' => ['uuid' => $asset->uuid, 'title' => $asset->title, 'kind' => $asset->kind]]);
    }

    public function destroy(Request $request, UploadSession $upload): JsonResponse
    {
        $this->own($request, $upload);
        $this->uploads->abort($upload);

        return response()->json(['ok' => true]);
    }

    private function own(Request $request, UploadSession $upload): void
    {
        abort_unless($upload->user_id === $request->user()->id || $request->user()->role === 'admin', 404);
    }
}
