<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MaterialRequestPrefillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MaterialRequestPrefillController extends Controller
{
    public function __construct(private MaterialRequestPrefillService $prefill) {}

    public function token(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => ['required', 'string', 'max:4096'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $this->prefill->verify($validator->validated()['token']);

        return response()->json($this->prefill->prefillFromPayload($payload));
    }

    public function startOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        if (blank($data['email'] ?? null) && blank($data['phone'] ?? null)) {
            return response()->json(['message' => 'Enter your phone or email first.', 'errors' => ['email' => ['Enter your phone or email first.']]], 422);
        }

        return response()->json($this->prefill->startOtp($data['email'] ?? null, $data['phone'] ?? null));
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'challenge' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'max:12'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        return response()->json($this->prefill->verifyOtp($data['challenge'], $data['code']));
    }
}
