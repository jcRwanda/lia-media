<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Services\DerivativeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DerivativeController extends Controller
{
    public function __construct(private DerivativeService $derivs) {}

    public function store(Request $request, Asset $asset): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(config('media.derivatives')))],
            'mime_type' => ['required', 'string'],
            'width' => ['required', 'integer', 'min:1'],
            'height' => ['required', 'integer', 'min:1'],
            'size_bytes' => ['required', 'integer', 'min:1', 'max:'.config('media.derivative_max_bytes')],
            'sha256_base64' => ['nullable', 'string', 'size:44'],
        ]);

        return response()->json($this->derivs->initiate($asset, $data), 201);
    }

    public function complete(Asset $asset, string $type): JsonResponse
    {
        $d = $this->derivs->complete($asset, $type);

        return response()->json(['derivative' => $d->only(['type', 'status', 'width', 'height'])]);
    }
}
