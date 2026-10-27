<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\MaterialRequestIssuedMail;
use App\Mail\MaterialRequestReturnedMail;
use App\Mail\MaterialRequestStatusMail;
use App\Models\ApiClient;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestFinalWork;
use App\Models\MaterialRequestItem;
use App\Services\MaterialRequestAuditService;
use App\Services\MaterialRequestService;
use App\Services\MaterialRequestWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MaterialRequestController extends Controller
{
    public function __construct(
        private MaterialRequestAuditService $audit,
        private MaterialRequestService $files,
        private MaterialRequestWebhookService $webhooks,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()?->canDo('material-requests.view')) {
            abort(403);
        }

        $base = MaterialRequest::query();

        if ($request->filled('status')) {
            $base->where('status', $request->input('status'));
        }
        if ($request->filled('requester_type')) {
            $base->where('requester_type', $request->input('requester_type'));
        }
        if ($request->boolean('overdue')) {
            $base->whereNotNull('return_by')
                ->whereIn('status', ['approved', 'issued'])
                ->whereDate('return_by', '<', now()->toDateString());
        }

        $directLabel = 'Direct/Hosted form';
        $total = (clone $base)->count();
        $counts = (clone $base)
            ->select('source')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('source')
            ->pluck('aggregate', 'source');

        $sources = [['key' => 'all', 'label' => 'All', 'count' => $total]];
        foreach (ApiClient::query()->orderBy('name')->get(['id', 'name']) as $client) {
            $sources[] = [
                'key' => $client->name,
                'label' => $client->name,
                'count' => (int) ($counts[$client->name] ?? 0),
            ];
        }
        $sources[] = [
            'key' => $directLabel,
            'label' => $directLabel,
            'count' => (int) ($counts[$directLabel] ?? 0) + (int) ($counts[''] ?? 0),
        ];

        $query = (clone $base)->with(['items.equipmentItem', 'apiClient:id,name']);

        if ($request->filled('source')) {
            $source = (string) $request->input('source');
            if ($source === $directLabel) {
                $query->where(fn ($inner) => $inner->whereNull('source')->orWhere('source', $directLabel));
            } else {
                $query->where('source', $source);
            }
        }
        if ($request->filled('api_client_id')) {
            $query->where('api_client_id', $request->integer('api_client_id'));
        }

        $requests = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 25));
        $payload = $requests->toArray();
        $payload['sources'] = $sources;

        return response()->json($payload);
    }

    public function show(Request $request, MaterialRequest $materialRequest): JsonResponse
    {
        if (! $request->user()?->canDo('material-requests.view')) {
            abort(403);
        }

        if ($materialRequest->requester_type === 'trainee') {
            if (in_array($materialRequest->status, ['issued', 'returned']) && $materialRequest->final_work_status === 'not_required') {
                $materialRequest->forceFill(['final_work_status' => 'pending'])->save();
            }
        }

        $materialRequest->load(['items.equipmentItem.images', 'items.proofs', 'items.issuedBy:id,name', 'items.receivedBy:id,name', 'audits.actor', 'cohort:id,name,code', 'apiClient:id,name', 'finalWorks', 'finalWorkReviewedBy']);

        $payload = $materialRequest->toArray();
        $payload['source'] = $materialRequest->source ?? $materialRequest->apiClient?->name ?? 'Direct/Hosted form';
        $payload['source_label'] = $payload['source'];
        $payload['is_overdue'] = $materialRequest->isOverdue();
        $payload['pickup_location'] = $this->pickupLocation($materialRequest);
        $payload['whatsapp_url'] = $this->whatsappUrl($materialRequest);
        $payload['items'] = $materialRequest->items->map(function ($item) {
            $data = $item->toArray();
            $data['issued_by'] = $item->issuedBy?->name;
            $data['received_by'] = $item->receivedBy?->name;
            $data['issue_proof_urls'] = $item->proofs->where('type', 'issue')
                ->map(fn ($proof) => $this->files->presign($proof->path, $proof->name))
                ->filter()->values()->all();
            $data['return_proof_urls'] = $item->proofs->where('type', 'return')
                ->map(fn ($proof) => $this->files->presign($proof->path, $proof->name))
                ->filter()->values()->all();
            if ($item->equipmentItem) {
                $data['equipment_item'] = [
                    ...$item->equipmentItem->toArray(),
                    'images' => $item->equipmentItem->images->map(fn ($image) => [
                        'id' => $image->id,
                        'name' => $image->name,
                        'url' => $this->files->presign($image->path, $image->name),
                    ])->values()->all(),
                ];
            }

            return $data;
        })->values()->all();
        $payload['id_document_url'] = $this->files->presign($materialRequest->id_document_path, $materialRequest->id_document_name);
        $payload['signature_url'] = $this->files->presign($materialRequest->signature_path, 'signature.png');
        $payload['final_works'] = $materialRequest->finalWorks->map(fn ($fw) => [
            'id' => $fw->id,
            'link' => $fw->link,
            'file_name' => $fw->file_name,
            'file_mime' => $fw->file_mime,
            'note' => $fw->note,
            'submitted_by_source' => $fw->submitted_by_source,
            'created_at' => $fw->created_at?->toIso8601String(),
            'download_url' => $fw->file_path ? url('/api/material-requests/'.$materialRequest->id.'/final-work/'.$fw->id.'/file') : null,
        ])->values();

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
        $this->webhooks->dispatchIfConfigured($materialRequest, 'submitted');

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
        $this->webhooks->dispatchIfConfigured($materialRequest, 'submitted');

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

        $rules = [
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'integer', 'exists:material_request_items,id'],
            'items.*.qty_issued' => ['required', 'integer', 'min:1'],
            'items.*.issue_condition' => ['nullable', 'string'],
            'items.*.note' => ['nullable', 'string'],
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        DB::transaction(function () use ($request, $materialRequest) {
            $user = $request->user();

            $itemsData = $request->input('items', []);
            foreach ($itemsData as $idx => $itemData) {
                $item = MaterialRequestItem::query()->find($itemData['id'] ?? null);
                if (! $item || $item->material_request_id !== $materialRequest->id) {
                    continue;
                }

                // Collect proofs (multiple or single)
                $proofFiles = [];
                if ($request->hasFile("items.{$idx}.issue_proofs")) {
                    $files = $request->file("items.{$idx}.issue_proofs");
                    if (is_array($files)) {
                        $proofFiles = array_merge($proofFiles, $files);
                    } else {
                        $proofFiles[] = $files;
                    }
                }
                if ($request->hasFile("items.{$idx}.issue_proof")) {
                    $proof = $request->file("items.{$idx}.issue_proof");
                    if (is_array($proof)) {
                        $proofFiles = array_merge($proofFiles, $proof);
                    } else {
                        $proofFiles[] = $proof;
                    }
                }

                if (empty($proofFiles)) {
                    throw ValidationException::withMessages(["items.{$idx}.issue_proof" => ['Photo proof required.']]);
                }

                $firstPath = null;
                foreach ($proofFiles as $pIdx => $proof) {
                    if (! $proof->isValid() || ! str_starts_with($proof->getMimeType() ?? '', 'image/')) {
                        throw ValidationException::withMessages(["items.{$idx}.issue_proof" => ['Invalid image.']]);
                    }
                    if ($proof->getSize() > 10240 * 1024) {
                        throw ValidationException::withMessages(["items.{$idx}.issue_proof" => ['Image too large.']]);
                    }
                    $path = $proof->store('material-requests/issue-proofs', 'public');
                    if ($firstPath === null) {
                        $firstPath = $path;
                    }
                    $item->proofs()->create([
                        'type' => 'issue',
                        'path' => $path,
                        'name' => $proof->getClientOriginalName(),
                        'mime' => $proof->getMimeType(),
                        'sort_order' => $pIdx,
                    ]);
                }

                $item->update([
                    'qty_issued' => $itemData['qty_issued'],
                    'issue_condition' => $itemData['issue_condition'] ?? null,
                    'note' => $itemData['note'] ?? $item->note,
                    'issue_proof_path' => $firstPath ?? $item->issue_proof_path,
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
        $this->webhooks->dispatchIfConfigured($materialRequest, 'approved');

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

        $previousStatus = $materialRequest->status;

        $rules = [
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'integer', 'exists:material_request_items,id'],
            'items.*.qty_returned' => ['required', 'integer', 'min:1'],
            'items.*.return_condition' => ['nullable', 'string'],
            'items.*.note' => ['nullable', 'string'],
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        DB::transaction(function () use ($request, $materialRequest) {
            $user = $request->user();

            $itemsData = $request->input('items', []);
            foreach ($itemsData as $idx => $itemData) {
                $item = MaterialRequestItem::query()->find($itemData['id'] ?? null);
                if (! $item || $item->material_request_id !== $materialRequest->id) {
                    continue;
                }

                $proofFiles = [];
                if ($request->hasFile("items.{$idx}.return_proofs")) {
                    $files = $request->file("items.{$idx}.return_proofs");
                    if (is_array($files)) {
                        $proofFiles = array_merge($proofFiles, $files);
                    } else {
                        $proofFiles[] = $files;
                    }
                }
                if ($request->hasFile("items.{$idx}.return_proof")) {
                    $proof = $request->file("items.{$idx}.return_proof");
                    if (is_array($proof)) {
                        $proofFiles = array_merge($proofFiles, $proof);
                    } else {
                        $proofFiles[] = $proof;
                    }
                }

                if (empty($proofFiles)) {
                    throw ValidationException::withMessages(["items.{$idx}.return_proof" => ['Photo proof required.']]);
                }

                $firstPath = null;
                foreach ($proofFiles as $pIdx => $proof) {
                    if (! $proof->isValid() || ! str_starts_with($proof->getMimeType() ?? '', 'image/')) {
                        throw ValidationException::withMessages(["items.{$idx}.return_proof" => ['Invalid image.']]);
                    }
                    if ($proof->getSize() > 10240 * 1024) {
                        throw ValidationException::withMessages(["items.{$idx}.return_proof" => ['Image too large.']]);
                    }
                    $path = $proof->store('material-requests/return-proofs', 'public');
                    if ($firstPath === null) {
                        $firstPath = $path;
                    }
                    $item->proofs()->create([
                        'type' => 'return',
                        'path' => $path,
                        'name' => $proof->getClientOriginalName(),
                        'mime' => $proof->getMimeType(),
                        'sort_order' => $pIdx,
                    ]);
                }

                $returned = (int) $itemData['qty_returned'];
                $item->update([
                    'qty_returned' => ($item->qty_returned ?? 0) + $returned,
                    'return_condition' => $itemData['return_condition'] ?? null,
                    'note' => $itemData['note'] ?? $item->note,
                    'return_proof_path' => $firstPath ?? $item->return_proof_path,
                    'returned_at' => now(),
                    'received_by' => $user?->id,
                    'status' => 'returned',
                ]);
            }

            $materialRequest->refresh();
            $allDone = true;
            foreach ($materialRequest->items()->get() as $it) {
                if (($it->qty_returned ?? 0) < ($it->qty_issued ?? $it->qty_approved ?? $it->qty_requested)) {
                    $allDone = false;
                    break;
                }
            }

            if ($allDone) {
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

            $this->audit->log($materialRequest, $allDone ? 'closed' : 'returned', [], $user);
        });

        $materialRequest->refresh();
        $this->webhooks->dispatchIfConfigured($materialRequest, $previousStatus);

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

    public function finalWorkFile(Request $request, MaterialRequest $materialRequest, MaterialRequestFinalWork $finalWork): StreamedResponse
    {
        if (! $request->user()?->canDo('material-requests.view')) {
            abort(403);
        }
        if ($finalWork->material_request_id !== $materialRequest->id) {
            abort(404);
        }
        if (! $finalWork->file_path) {
            abort(404);
        }

        $disk = 'r2';
        if (! config("filesystems.disks.{$disk}")) {
            $disk = 'local';
        }

        if (Storage::disk($disk)->exists($finalWork->file_path)) {
            return Storage::disk($disk)->download($finalWork->file_path, $finalWork->file_name ?? basename($finalWork->file_path));
        }

        abort(404);
    }

    public function acceptFinalWork(Request $request, MaterialRequest $materialRequest): JsonResponse
    {
        if (! $request->user()?->canDo('material-requests.manage')) {
            abort(403);
        }
        if ($materialRequest->requester_type !== 'trainee') {
            abort(403, 'Final work is only for trainee requests.');
        }

        $materialRequest->forceFill([
            'final_work_status' => 'accepted',
            'final_work_reviewed_at' => now(),
            'final_work_reviewed_by' => $request->user()?->id,
            'final_work_review_note' => $request->input('note'),
        ])->save();

        $this->audit->log($materialRequest, 'final_work_accepted', ['note' => $request->input('note')], $request->user());
        $this->webhooks->dispatchEvent($materialRequest->fresh(['apiClient']), 'final_work.accepted');

        return response()->json(['message' => 'Final work accepted', 'final_work_status' => $materialRequest->final_work_status]);
    }

    public function requestFinalWorkChanges(Request $request, MaterialRequest $materialRequest): JsonResponse
    {
        if (! $request->user()?->canDo('material-requests.manage')) {
            abort(403);
        }
        if ($materialRequest->requester_type !== 'trainee') {
            abort(403, 'Final work is only for trainee requests.');
        }

        $validator = Validator::make($request->all(), [
            'note' => ['required', 'string', 'max:2000'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $materialRequest->forceFill([
            'final_work_status' => 'needs_changes',
            'final_work_reviewed_at' => now(),
            'final_work_reviewed_by' => $request->user()?->id,
            'final_work_review_note' => $validator->validated()['note'],
        ])->save();

        $this->audit->log($materialRequest, 'final_work_needs_changes', ['note' => $validator->validated()['note']], $request->user());
        $this->webhooks->dispatchEvent($materialRequest->fresh(['apiClient']), 'final_work.needs_changes');

        return response()->json(['message' => 'Changes requested', 'final_work_status' => $materialRequest->final_work_status]);
    }

    private function pickupLocation(MaterialRequest $materialRequest): string
    {
        return (string) ($materialRequest->project_location
            ?: $materialRequest->shoot_location
            ?: collect([$materialRequest->project_cell, $materialRequest->project_sector, $materialRequest->project_district, $materialRequest->project_province])->filter()->join(', ')
            ?: $materialRequest->residential_address
            ?: '');
    }

    private function whatsappUrl(MaterialRequest $materialRequest): ?string
    {
        $phone = preg_replace('/[^0-9]/', '', (string) $materialRequest->phone);
        if (! $phone) {
            return null;
        }
        if (str_starts_with($phone, '00')) {
            $phone = substr($phone, 2);
        }
        if (str_starts_with($phone, '0')) {
            $phone = '250'.substr($phone, 1);
        } elseif (! str_starts_with($phone, '250')) {
            $phone = '250'.$phone;
        }

        $items = $materialRequest->items
            ->map(function ($item) {
                $name = $item->equipmentItem?->name ?? 'Item';
                $qty = $item->qty_approved ?? $item->qty_issued ?? $item->qty_requested;

                return $qty ? "- {$name} x{$qty}" : "- {$name}";
            })
            ->join("\n");

        $text = "Booking summary\nReference: {$materialRequest->reference_code}\nName: {$materialRequest->full_name}\nItems:\n{$items}\nPickup: ".($materialRequest->needed_from?->toDateString() ?? '')."\nReturn: ".($materialRequest->return_by?->toDateString() ?? '')."\nPickup location: {$this->pickupLocation($materialRequest)}";

        return 'https://wa.me/'.$phone.'?text='.rawurlencode($text);
    }
}
