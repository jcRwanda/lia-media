<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\MaterialRequestIssuedMail;
use App\Mail\MaterialRequestReturnedMail;
use App\Mail\MaterialRequestStatusMail;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Services\MaterialRequestAuditService;
use App\Services\MaterialRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MaterialRequestController extends Controller
{
    public function __construct(private MaterialRequestAuditService $audit, private MaterialRequestService $files) {}

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()?->canDo('material-requests.view')) {
            abort(403);
        }

        $query = MaterialRequest::query()->with('items.equipmentItem');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('requester_type')) {
            $query->where('requester_type', $request->input('requester_type'));
        }
        if ($request->boolean('overdue')) {
            $query->whereNotNull('return_by')
                ->whereIn('status', ['approved', 'issued'])
                ->whereDate('return_by', '<', now()->toDateString());
        }

        $requests = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 25));

        return response()->json($requests);
    }

    public function show(Request $request, MaterialRequest $materialRequest): JsonResponse
    {
        if (! $request->user()?->canDo('material-requests.view')) {
            abort(403);
        }

        $materialRequest->load(['items.equipmentItem', 'audits.actor', 'cohort:id,name,code']);

        $payload = $materialRequest->toArray();
        $payload['is_overdue'] = $materialRequest->isOverdue();
        $payload['id_document_url'] = $this->files->presign($materialRequest->id_document_path, $materialRequest->id_document_name);
        $payload['signature_url'] = $this->files->presign($materialRequest->signature_path, 'signature.png');

        return response()->json(['request' => $payload]);
    }

    public function approve(Request $request, MaterialRequest $materialRequest): JsonResponse
    {
        if (! $request->user()?->canDo('material-requests.manage')) {
            abort(403);
        }

        if (! in_array($materialRequest->status, ['submitted'], true)) {
            return response()->json(['message' => 'Invalid state transition'], 422);
        }

        $validator = Validator::make($request->all(), [
            'note' => ['nullable', 'string'],
            'items' => ['nullable', 'array'],
            'items.*.id' => ['required', 'integer', 'exists:material_request_items,id'],
            'items.*.qty_approved' => ['required', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        DB::transaction(function () use ($request, $materialRequest, $validator) {
            $user = $request->user();
            $materialRequest->update([
                'status' => 'approved',
                'review_note' => $validator->validated()['note'] ?? null,
                'reviewed_by' => $user?->id,
                'reviewed_at' => now(),
                'approved_by' => $user?->id,
                'approved_at' => now(),
            ]);

            if (! empty($validator->validated()['items'])) {
                foreach ($validator->validated()['items'] as $itemData) {
                    $item = MaterialRequestItem::query()->find($itemData['id']);
                    if ($item && $item->material_request_id === $materialRequest->id) {
                        $item->update([
                            'qty_approved' => $itemData['qty_approved'],
                            'status' => $itemData['qty_approved'] > 0 ? 'approved' : 'rejected',
                        ]);
                    }
                }
            } else {
                foreach ($materialRequest->items as $item) {
                    $item->update([
                        'qty_approved' => $item->qty_requested,
                        'status' => 'approved',
                    ]);
                }
            }

            $this->audit->log($materialRequest, 'approved', ['note' => $materialRequest->review_note], $user);
        });

        $materialRequest->refresh();

        try {
            Mail::to($materialRequest->email)->queue(new MaterialRequestStatusMail($materialRequest));
        } catch (\Throwable $e) {
        }

        return response()->json(['request' => $materialRequest->load('items.equipmentItem')]);
    }

    public function reject(Request $request, MaterialRequest $materialRequest): JsonResponse
    {
        if (! $request->user()?->canDo('material-requests.manage')) {
            abort(403);
        }

        if (! in_array($materialRequest->status, ['submitted'], true)) {
            return response()->json(['message' => 'Invalid state transition'], 422);
        }

        $validator = Validator::make($request->all(), [
            'note' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        DB::transaction(function () use ($request, $materialRequest, $validator) {
            $user = $request->user();
            $materialRequest->update([
                'status' => 'rejected',
                'review_note' => $validator->validated()['note'],
                'reviewed_by' => $user?->id,
                'reviewed_at' => now(),
                'rejected_by' => $user?->id,
                'rejected_at' => now(),
            ]);

            foreach ($materialRequest->items as $item) {
                $item->update(['status' => 'rejected']);
            }

            $this->audit->log($materialRequest, 'rejected', ['note' => $materialRequest->review_note], $user);
        });

        $materialRequest->refresh();

        try {
            Mail::to($materialRequest->email)->queue(new MaterialRequestStatusMail($materialRequest));
        } catch (\Throwable $e) {
        }

        return response()->json(['request' => $materialRequest->load('items.equipmentItem')]);
    }

    public function issue(Request $request, MaterialRequest $materialRequest): JsonResponse
    {
        if (! $request->user()?->canDo('material-requests.issue')) {
            abort(403);
        }

        if (! in_array($materialRequest->status, ['approved'], true)) {
            return response()->json(['message' => 'Invalid state transition'], 422);
        }

        $validator = Validator::make($request->all(), [
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'integer', 'exists:material_request_items,id'],
            'items.*.qty_issued' => ['required', 'integer', 'min:1'],
            'items.*.issue_condition' => ['nullable', 'string'],
            'items.*.issue_proof' => ['required', 'file', 'image', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        DB::transaction(function () use ($request, $materialRequest, $validator) {
            $user = $request->user();

            foreach ($validator->validated()['items'] as $idx => $itemData) {
                $item = MaterialRequestItem::query()->find($itemData['id']);
                if (! $item || $item->material_request_id !== $materialRequest->id) {
                    continue;
                }

                $proof = $request->file("items.{$idx}.issue_proof") ?? null;
                if (! $proof) {
                    throw new ValidationException($validator);
                }

                $path = $proof->store('material-requests/issue-proofs', 'public');

                $item->update([
                    'qty_issued' => $itemData['qty_issued'],
                    'issue_condition' => $itemData['issue_condition'] ?? null,
                    'issue_proof_path' => $path,
                    'issued_at' => now(),
                    'issued_by' => $user?->id,
                    'status' => 'issued',
                ]);
            }

            $materialRequest->update([
                'status' => 'issued',
            ]);

            $this->audit->log($materialRequest, 'issued', [], $user);
        });

        $materialRequest->refresh();

        try {
            Mail::to($materialRequest->email)->queue(new MaterialRequestIssuedMail($materialRequest));
        } catch (\Throwable $e) {
        }

        return response()->json(['request' => $materialRequest->load('items.equipmentItem')]);
    }

    public function return(Request $request, MaterialRequest $materialRequest): JsonResponse
    {
        if (! $request->user()?->canDo('material-requests.return')) {
            abort(403);
        }

        if (! in_array($materialRequest->status, ['issued', 'approved'], true)) {
            // allow returning even if partially issued
        }

        $validator = Validator::make($request->all(), [
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'integer', 'exists:material_request_items,id'],
            'items.*.qty_returned' => ['required', 'integer', 'min:1'],
            'items.*.return_condition' => ['nullable', 'string'],
            'items.*.return_proof' => ['required', 'file', 'image', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        DB::transaction(function () use ($request, $materialRequest, $validator) {
            $user = $request->user();
            $allReturned = true;

            foreach ($validator->validated()['items'] as $idx => $itemData) {
                $item = MaterialRequestItem::query()->find($itemData['id']);
                if (! $item || $item->material_request_id !== $materialRequest->id) {
                    continue;
                }

                $proof = $request->file("items.{$idx}.return_proof") ?? null;
                if (! $proof) {
                    throw new ValidationException($validator);
                }

                $path = $proof->store('material-requests/return-proofs', 'public');

                $returned = $itemData['qty_returned'];
                $item->update([
                    'qty_returned' => ($item->qty_returned ?? 0) + $returned,
                    'return_condition' => $itemData['return_condition'] ?? null,
                    'return_proof_path' => $path,
                    'returned_at' => now(),
                    'received_by' => $user?->id,
                    'status' => 'returned',
                ]);

                if (($item->qty_returned ?? 0) < ($item->qty_issued ?? $item->qty_approved ?? $item->qty_requested)) {
                    $allReturned = false;
                }
            }

            if ($allReturned) {
                $materialRequest->update([
                    'status' => 'closed',
                    'closed_by' => $user?->id,
                    'closed_at' => now(),
                ]);
            } else {
                $materialRequest->update([
                    'status' => 'returned',
                ]);
            }

            $this->audit->log($materialRequest, $allReturned ? 'closed' : 'returned', [], $user);
        });

        $materialRequest->refresh();

        try {
            Mail::to($materialRequest->email)->queue(new MaterialRequestReturnedMail($materialRequest));
        } catch (\Throwable $e) {
        }

        return response()->json(['request' => $materialRequest->load('items.equipmentItem')]);
    }

    public function idDocument(Request $request, MaterialRequest $materialRequest): JsonResponse
    {
        if (! $request->user()?->canDo('material-requests.view')) {
            abort(403);
        }
        if (! $materialRequest->id_document_path) {
            abort(404);
        }

        return response()->json([
            'url' => $this->files->presign($materialRequest->id_document_path, $materialRequest->id_document_name),
            'name' => $materialRequest->id_document_name,
        ]);
    }

    public function signature(Request $request, MaterialRequest $materialRequest): JsonResponse
    {
        if (! $request->user()?->canDo('material-requests.view')) {
            abort(403);
        }
        if (! $materialRequest->signature_path) {
            abort(404);
        }

        return response()->json(['url' => $this->files->presign($materialRequest->signature_path, 'signature.png')]);
    }

    public function audits(Request $request, MaterialRequest $materialRequest): JsonResponse
    {
        if (! $request->user()?->canDo('material-requests.view')) {
            abort(403);
        }

        $audits = $materialRequest->audits()->with('actor')->orderByDesc('created_at')->get();

        return response()->json(['audits' => $audits]);
    }
}
