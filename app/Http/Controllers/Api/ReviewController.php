<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Services\AssetPresenter;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReviewController extends Controller
{
    public function __construct(private ReviewService $review, private AssetPresenter $present) {}

    public function update(Request $request, Asset $asset): JsonResponse
    {
        $d = $request->validate(['to_state' => ['required', Rule::in(Asset::REVIEW_STATES)], 'note' => ['nullable', 'string', 'max:2000']]);
        $this->review->transition($asset, $d['to_state'], $request->user(), $d['note'] ?? null);

        return response()->json(['asset' => $this->present->detail($asset->fresh())]);
    }

    public function bulk(Request $request): JsonResponse
    {
        $d = $request->validate(['uuids' => ['required', 'array', 'min:1', 'max:200'], 'uuids.*' => ['string'], 'to_state' => ['required', Rule::in(Asset::REVIEW_STATES)], 'note' => ['nullable', 'string', 'max:2000']]);
        $done = 0;
        $failed = [];
        foreach (Asset::query()->whereIn('uuid', $d['uuids'])->get() as $a) {
            try {
                $this->review->transition($a, $d['to_state'], $request->user(), $d['note'] ?? null);
                $done++;
            } catch (ValidationException $e) {
                $failed[] = ['uuid' => $a->uuid, 'title' => $a->title, 'error' => collect($e->errors())->flatten()->first()];
            }
        }

        return response()->json(['updated' => $done, 'failed' => $failed]);
    }
}
