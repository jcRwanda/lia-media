<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\MaterialRequestAdminNotificationMail;
use App\Mail\MaterialRequestConfirmationMail;
use App\Models\ApiClient;
use App\Models\Cohort;
use App\Models\EquipmentItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestFinalWork;
use App\Models\MaterialRequestItem;
use App\Models\User;
use App\Services\EquipmentAvailabilityService;
use App\Services\MaterialRequestAuditService;
use App\Services\MaterialRequestPrefillService;
use App\Services\MaterialRequestService;
use App\Services\MaterialRequestWebhookService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MaterialRequestPublicController extends Controller
{
    public function __construct(
        private MaterialRequestAuditService $audit,
        private MaterialRequestService $files,
        private MaterialRequestPrefillService $prefill,
        private EquipmentAvailabilityService $availability,
        private MaterialRequestWebhookService $webhooks,
    ) {}

    /**
     * Catalog of requestable items (available qty)
     */
    public function catalog(Request $request): JsonResponse
    {
        $type = $request->query('type');
        $query = EquipmentItem::query()->orderBy('name');

        if ($type === 'trainee') {
            $query->where('allowed_for_trainees', true);
        }

        $items = $query->get()
            ->map(fn (EquipmentItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'category' => $item->category,
                'condition' => $item->condition,
                'available_qty' => $item->total_qty,
                'allowed_for_trainees' => (bool) $item->allowed_for_trainees,
                'photo_path' => $item->photo_path,
            ]);

        return response()->json(['items' => $items]);
    }

    /**
     * Submit request
     */
    public function store(Request $request): JsonResponse
    {
        // honeypot/basic anti-spam
        $honeypot = config('material-requests.honey_pot_name', 'website_url');
        if ($request->filled($honeypot)) {
            return response()->json(['message' => 'Request submitted'], 200);
        }

        $data = $request->all();

        // Keep the public API friendly to the original keys and the exact question list.
        $data['needed_from'] = $data['needed_from'] ?? $data['start_date'] ?? null;
        $data['return_by'] = $data['return_by'] ?? $data['end_date'] ?? null;
        $data['purpose_project_description'] = $data['purpose_project_description'] ?? $data['project_summary'] ?? null;
        $data['village_res'] = $data['village_res'] ?? $data['village'] ?? null;
        $data['project_village'] = $data['project_village'] ?? $data['project_village_res'] ?? null;
        $data['cohort_id'] = $data['cohort_id'] ?? $data['cohort'] ?? null;
        $data['terms_credit'] = $data['terms_credit'] ?? $data['terms_accepted'] ?? null;

        $requesterType = $data['requester_type'] ?? 'client';
        $requiresId = in_array($requesterType, ['client', 'alumni'], true);
        $hasIdInput = ! empty($data['id_document']) || ! empty($data['reuse_id_token']);
        $cohortRequired = in_array($requesterType, ['trainee', 'alumni'], true);

        if ($requesterType === 'trainee') {
            if (config('material-requests.block_new_trainee_requests_with_overdue_pending_final_work', true)) {
                $traineeEmail = strtolower((string) ($data['email'] ?? ''));
                $traineePhone = (string) ($data['phone'] ?? '');
                if ($traineeEmail !== '' || $traineePhone !== '') {
                    $hasOverduePendingFinalWork = MaterialRequest::query()
                        ->where('requester_type', 'trainee')
                        ->where(function ($q) use ($traineeEmail, $traineePhone) {
                            if ($traineeEmail !== '') {
                                $q->orWhereRaw('LOWER(email) = ?', [$traineeEmail]);
                            }
                            if ($traineePhone !== '') {
                                $q->orWhere('phone', $traineePhone);
                            }
                        })
                        ->whereNotNull('return_by')
                        ->whereDate('return_by', '<', now()->toDateString())
                        ->whereIn('status', ['issued', 'returned'])
                        ->where(function ($q) {
                            $q->where('final_work_status', 'pending')
                                ->orWhere(function ($q2) {
                                    $q2->where('final_work_status', 'not_required')
                                        ->doesntHave('finalWorks');
                                });
                        })
                        ->exists();
                    if ($hasOverduePendingFinalWork) {
                        throw ValidationException::withMessages(['email' => 'You have overdue pending final work for a previous trainee request. Submit your final work before requesting new equipment.']);
                    }
                }
            }

            $rules = [
                'requester_type' => ['required', Rule::in(['trainee'])],
                'full_name' => ['required', 'string', 'max:255'],
                'phone' => ['required', 'string', 'max:50'],
                'email' => ['required', 'email', 'max:255'],
                'cohort_id' => ['required', 'integer', 'exists:cohorts,id'],
                'student_alumni_id' => ['nullable', 'string', 'max:255'],
                'items' => ['required', 'array', 'min:1'],
                'items.*.equipment_item_id' => ['required', 'integer', 'exists:equipment_items,id'],
                'items.*.qty' => ['required', 'integer', 'min:1'],
                'needed_from' => ['required', 'date'],
                'return_by' => ['required', 'date', 'after_or_equal:needed_from'],
                'shoot_location' => ['required', 'string', 'max:500'],
                'shoot_type' => ['required', Rule::in(['video', 'photo', 'both'])],
                'project_name' => ['nullable', 'string', 'max:255'],
                'purpose_project_description' => ['nullable', 'string'],
                'terms_credit' => ['required', 'accepted'],
                'consent_declaration' => ['required', 'accepted'],
                'signature' => ['required'],
                'source_detail' => ['nullable', 'string', 'max:255'],
            ];
        } else {
            $rules = [
                'requester_type' => ['required', Rule::in(config('material-requests.requester_types', ['client', 'trainee', 'alumni']))],
                'full_name' => ['required', 'string', 'max:255'],
                'phone' => ['required', 'string', 'max:50'],
                'email' => ['required', 'email', 'max:255'],
                'project_name' => ['required', 'string', 'max:255'],
                'national_id' => ['nullable', 'string', 'max:100'],
                'province_res' => ['nullable', 'string', 'max:255'],
                'district_res' => ['nullable', 'string', 'max:255'],
                'sector_res' => ['nullable', 'string', 'max:255'],
                'cell_res' => ['nullable', 'string', 'max:255'],
                'village_res' => ['nullable', 'string', 'max:255'],
                'residential_address' => ['nullable', 'string', 'max:500'],
                'emergency_contact_name' => ['required', 'string', 'max:255'],
                'emergency_contact_phone' => ['required', 'string', 'max:50'],
                'relationship' => ['required', 'string', 'max:255'],
                'purpose_project_description' => ['required', 'string'],
                'project_province' => ['nullable', 'string', 'max:255'],
                'project_district' => ['nullable', 'string', 'max:255'],
                'project_sector' => ['nullable', 'string', 'max:255'],
                'project_cell' => ['nullable', 'string', 'max:255'],
                'project_village' => ['nullable', 'string', 'max:255'],
                'project_location' => ['nullable', 'string', 'max:500'],
                'project_same_as_residential' => ['nullable', 'boolean'],
                'needed_from' => ['required', 'date'],
                'return_by' => ['required', 'date', 'after_or_equal:needed_from'],
                'terms_accepted' => ['required', 'accepted'],
                'consent_declaration' => ['required', 'accepted'],
                'signature' => ['required'],
                'reuse_id_token' => ['nullable', 'string', 'max:64'],
                'id_document' => [Rule::requiredIf($requiresId && ! $hasIdInput), 'nullable'],
                'items' => ['required', 'array', 'min:1'],
                'items.*.equipment_item_id' => ['required', 'integer', 'exists:equipment_items,id'],
                'items.*.qty' => ['required', 'integer', 'min:1'],
                'cohort_id' => [Rule::requiredIf($cohortRequired), 'nullable', 'integer', 'exists:cohorts,id'],
                'source_detail' => ['nullable', 'string', 'max:255'],
            ];

            if ($cohortRequired) {
                $rules['program_cohort'] = ['nullable', 'string', 'max:255'];
                $rules['graduation_completion_year'] = ['nullable', 'string', 'max:10'];
                $rules['student_alumni_id'] = ['nullable', 'string', 'max:255'];
                $rules['project_title'] = ['nullable', 'string', 'max:255'];
                $rules['project_type'] = ['nullable', 'string', 'max:255'];
                $rules['expected_outcome'] = ['nullable', 'string'];
                $rules['portfolio_social_link'] = ['nullable', 'string', 'max:500'];
            }
        }

        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $bookingLines = array_map(fn (array $item) => [
            'equipment_item_id' => (int) $item['equipment_item_id'],
            'qty' => (int) $item['qty'],
        ], $validated['items']);
        $neededFrom = CarbonImmutable::parse($validated['needed_from'])->startOfDay();
        $returnBy = CarbonImmutable::parse($validated['return_by'])->startOfDay();

        if ($requesterType === 'trainee') {
            $itemIds = array_column($bookingLines, 'equipment_item_id');
            $disallowed = EquipmentItem::query()->whereIn('id', $itemIds)->where(function ($q) {
                $q->where('allowed_for_trainees', false)->orWhereNull('allowed_for_trainees');
            })->pluck('id')->all();
            if (! empty($disallowed)) {
                $errors = [];
                foreach ($validated['items'] as $idx => $item) {
                    if (in_array((int) $item['equipment_item_id'], $disallowed, true)) {
                        $errors["items.{$idx}.equipment_item_id"] = ['This equipment item is not allowed for trainees.'];
                    }
                }
                if (! empty($errors)) {
                    throw ValidationException::withMessages($errors);
                }
            }
        }

        if (! empty($validated['project_same_as_residential'])) {
            $validated['project_province'] = $validated['province_res'] ?? null;
            $validated['project_district'] = $validated['district_res'] ?? null;
            $validated['project_sector'] = $validated['sector_res'] ?? null;
            $validated['project_cell'] = $validated['cell_res'] ?? null;
            $validated['project_village'] = $validated['project_village'] ?? ($validated['village_res'] ?? null);
        }

        if ($requesterType !== 'trainee') {
            $validated['residential_address'] = $validated['residential_address']
                ?? $this->composeAddress([
                    $validated['village_res'] ?? null,
                    $validated['cell_res'] ?? null,
                    $validated['sector_res'] ?? null,
                    $validated['district_res'] ?? null,
                    $validated['province_res'] ?? null,
                ]);
            $validated['project_location'] = $validated['project_location']
                ?? $this->composeAddress([
                    $validated['project_village'] ?? null,
                    $validated['project_cell'] ?? null,
                    $validated['project_sector'] ?? null,
                    $validated['project_district'] ?? null,
                    $validated['project_province'] ?? null,
                ]);
        } else {
            $validated['project_name'] = $validated['project_name'] ?? ($validated['purpose_project_description'] ? 'Trainee request' : null);
            $validated['purpose_project_description'] = $validated['purpose_project_description'] ?? null;
        }

        $this->availability->assertCanBook($bookingLines, $neededFrom, $returnBy);

        $reusedRequest = null;
        if ($requesterType !== 'trainee') {
            $reusedRequest = $this->prefill->consumeIdReuseToken(
                $validated['reuse_id_token'] ?? null,
                $validated['email'],
                $validated['phone'],
            );
        }

        $idDocument = null;
        if ($reusedRequest?->id_document_path) {
            $idDocument = [
                'path' => $reusedRequest->id_document_path,
                'mime' => $reusedRequest->id_document_mime,
                'name' => $reusedRequest->id_document_name,
            ];
        } elseif (($validated['id_document'] ?? null) instanceof UploadedFile) {
            $idDocument = $this->files->storeIdDocument($validated['id_document']);
        } elseif (is_string($validated['id_document'] ?? null) && $validated['id_document'] !== '') {
            $idDocument = $this->files->storeIdDocumentDataUri($validated['id_document']);
        }

        if ($requiresId && ! $idDocument) {
            throw ValidationException::withMessages(['id_document' => 'Upload your ID (image or PDF).']);
        }

        $signature = $validated['signature'] instanceof UploadedFile
            ? $this->files->storeSignature($validated['signature'])
            : $this->files->storeSignature((string) $validated['signature']);

        $cohort = ! empty($validated['cohort_id']) ? Cohort::query()->find($validated['cohort_id']) : null;
        $apiClient = $request->attributes->get('api_client');
        $apiClient = $apiClient instanceof ApiClient ? $apiClient : null;
        $source = $apiClient?->name ?? 'Direct/Hosted form';

        $materialRequest = DB::transaction(function () use ($validated, $bookingLines, $neededFrom, $returnBy, $idDocument, $signature, $cohort, $apiClient, $source) {
            $this->availability->assertCanBook($bookingLines, $neededFrom, $returnBy, lockItems: true);

            $materialRequest = MaterialRequest::query()->create([
                'api_client_id' => $apiClient?->id,
                'source' => $source,
                'source_detail' => $validated['source_detail'] ?? null,
                'requester_type' => $validated['requester_type'],
                'full_name' => $validated['full_name'],
                'phone' => $validated['phone'],
                'email' => $validated['email'],
                'project_name' => $validated['project_name'],
                'national_id' => $validated['national_id'] ?? null,
                'province_res' => $validated['province_res'] ?? null,
                'district_res' => $validated['district_res'] ?? null,
                'sector_res' => $validated['sector_res'] ?? null,
                'cell_res' => $validated['cell_res'] ?? null,
                'village_res' => $validated['village_res'] ?? null,
                'residential_address' => $validated['residential_address'] ?? null,
                'emergency_contact_name' => $validated['emergency_contact_name'] ?? null,
                'emergency_contact_phone' => $validated['emergency_contact_phone'] ?? null,
                'relationship' => $validated['relationship'] ?? null,
                'purpose_project_description' => $validated['purpose_project_description'] ?? null,
                'project_province' => $validated['project_province'] ?? null,
                'project_district' => $validated['project_district'] ?? null,
                'project_sector' => $validated['project_sector'] ?? null,
                'project_cell' => $validated['project_cell'] ?? null,
                'project_location' => $validated['project_location'] ?? null,
                'shoot_location' => $validated['shoot_location'] ?? null,
                'shoot_type' => $validated['shoot_type'] ?? null,
                'needed_from' => $validated['needed_from'],
                'return_by' => $validated['return_by'],
                'terms_accepted' => ! empty($validated['terms_accepted']) || ! empty($validated['terms_credit']),
                'terms_credit' => ! empty($validated['terms_credit']) || ! empty($validated['terms_accepted']),
                'consent_declaration' => true,
                'program_cohort' => $cohort?->name ?? ($validated['program_cohort'] ?? null),
                'cohort_id' => $cohort?->id ?? ($validated['cohort_id'] ?? null),
                'graduation_completion_year' => $validated['graduation_completion_year'] ?? null,
                'student_alumni_id' => $validated['student_alumni_id'] ?? null,
                'project_title' => $validated['project_title'] ?? null,
                'project_type' => $validated['project_type'] ?? null,
                'expected_outcome' => $validated['expected_outcome'] ?? null,
                'portfolio_social_link' => $validated['portfolio_social_link'] ?? null,
                'id_document_path' => $idDocument['path'] ?? null,
                'id_document_mime' => $idDocument['mime'] ?? null,
                'id_document_name' => $idDocument['name'] ?? null,
                'signature_path' => $signature['path'],
                'signature_date' => now()->toDateString(),
                'status' => 'submitted',
                'final_work_status' => $validated['requester_type'] === 'trainee' ? 'pending' : 'not_required',
            ]);

            foreach ($validated['items'] as $item) {
                MaterialRequestItem::query()->create([
                    'material_request_id' => $materialRequest->id,
                    'equipment_item_id' => $item['equipment_item_id'],
                    'qty_requested' => $item['qty'],
                    'status' => 'pending',
                ]);
            }

            return $materialRequest;
        });

        $this->audit->log($materialRequest, 'submitted', ['requester_type' => $materialRequest->requester_type], null, 'system');
        $this->webhooks->dispatchIfConfigured($materialRequest);

        try {
            Mail::to($materialRequest->email)->queue(new MaterialRequestConfirmationMail($materialRequest));
        } catch (\Throwable $e) {
            // ignore mail failures
        }

        try {
            $admins = User::query()->whereIn('role', ['admin', 'management', 'communications'])->get();
            foreach ($admins as $admin) {
                if ($admin->email) {
                    Mail::to($admin->email)->queue(new MaterialRequestAdminNotificationMail($materialRequest));
                }
            }
        } catch (\Throwable $e) {
            // ignore mail failures
        }

        return response()->json([
            'message' => 'Request submitted',
            'reference_code' => $materialRequest->reference_code,
            'status' => $materialRequest->status,
        ], 201);
    }

    /**
     * Get status by reference code + email/phone
     */
    public function status(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reference_code' => ['required', 'string'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $materialRequest = MaterialRequest::query()
            ->with('items.equipmentItem')
            ->where('reference_code', $validator->validated()['reference_code'])
            ->first();

        if (! $materialRequest) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $email = $validator->validated()['email'] ?? null;
        $phone = $validator->validated()['phone'] ?? null;

        if ($email && strtolower($materialRequest->email) !== strtolower($email)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($phone && $materialRequest->phone !== $phone) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json([
            'request' => [
                'reference_code' => $materialRequest->reference_code,
                'status' => $materialRequest->status,
                'requester_type' => $materialRequest->requester_type,
                'full_name' => $materialRequest->full_name,
                'review_note' => $materialRequest->review_note,
                'needed_from' => $materialRequest->needed_from?->format('Y-m-d'),
                'return_by' => $materialRequest->return_by?->format('Y-m-d'),
                'items' => $materialRequest->items->map(fn ($item) => [
                    'name' => $item->equipmentItem?->name,
                    'qty_requested' => $item->qty_requested,
                    'qty_approved' => $item->qty_approved,
                    'qty_issued' => $item->qty_issued,
                    'qty_returned' => $item->qty_returned,
                    'status' => $item->status,
                ]),
            ],
        ]);
    }

    public function showByReference(Request $request, string $reference): JsonResponse
    {
        $apiClient = $request->attributes->get('api_client');
        if (! $apiClient instanceof ApiClient) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $materialRequest = MaterialRequest::query()
            ->with(['items.equipmentItem', 'finalWorks'])
            ->where('reference_code', $reference)
            ->where('api_client_id', $apiClient->id)
            ->first();

        if (! $materialRequest) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($materialRequest->requester_type === 'trainee') {
            if (in_array($materialRequest->status, ['issued', 'returned']) && $materialRequest->final_work_status === 'not_required') {
                $materialRequest->forceFill(['final_work_status' => 'pending'])->save();
            }
        }

        return response()->json([
            'request' => [
                'reference_code' => $materialRequest->reference_code,
                'status' => $materialRequest->status,
                'requester_type' => $materialRequest->requester_type,
                'source' => $materialRequest->source,
                'source_detail' => $materialRequest->source_detail,
                'review_note' => $materialRequest->review_note,
                'final_work_status' => $materialRequest->final_work_status,
                'needed_from' => $materialRequest->needed_from?->format('Y-m-d'),
                'return_by' => $materialRequest->return_by?->format('Y-m-d'),
                'created_at' => $materialRequest->created_at?->toIso8601String(),
                'updated_at' => $materialRequest->updated_at?->toIso8601String(),
                'items' => $materialRequest->items->map(fn ($item) => [
                    'name' => $item->equipmentItem?->name,
                    'qty_requested' => $item->qty_requested,
                    'qty_approved' => $item->qty_approved,
                    'qty_issued' => $item->qty_issued,
                    'qty_returned' => $item->qty_returned,
                    'status' => $item->status,
                ])->values(),
                'final_works' => $materialRequest->finalWorks->map(fn ($fw) => [
                    'id' => $fw->id,
                    'link' => $fw->link,
                    'file_name' => $fw->file_name,
                    'file_mime' => $fw->file_mime,
                    'note' => $fw->note,
                    'submitted_by_source' => $fw->submitted_by_source,
                    'created_at' => $fw->created_at?->toIso8601String(),
                ])->values(),
            ],
        ]);
    }

    public function finalWorkUploadUrl(Request $request, string $reference): JsonResponse
    {
        $apiClient = $request->attributes->get('api_client');
        if (! $apiClient instanceof ApiClient) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $materialRequest = MaterialRequest::query()
            ->where('reference_code', $reference)
            ->where('api_client_id', $apiClient->id)
            ->first();

        if (! $materialRequest) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($materialRequest->requester_type !== 'trainee') {
            abort(403, 'Final work is only available for trainee requests.');
        }

        $data = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'content_type' => ['required', 'string', 'max:255'],
        ]);

        $allowed = config('material-requests.final_work_allowed_mimes', []);
        if (! empty($allowed) && ! in_array($data['content_type'], $allowed, true)) {
            return response()->json(['message' => 'Validation failed', 'errors' => ['content_type' => ['File type not allowed.']]], 422);
        }

        $ext = pathinfo($data['filename'], PATHINFO_EXTENSION);
        $key = 'material-requests/final-work/'.$materialRequest->id.'/'.Str::uuid().($ext ? '.'.$ext : '');
        $disk = 'r2';
        if (! config("filesystems.disks.{$disk}")) {
            $disk = 'local';
        }

        try {
            $client = Storage::disk($disk)->getClient();
            $bucket = config("filesystems.disks.{$disk}.bucket") ?? env('R2_BUCKET');
            $cmd = $client->getCommand('PutObject', [
                'Bucket' => $bucket,
                'Key' => $key,
                'ContentType' => $data['content_type'],
            ]);
            $requestPresigned = \Aws\S3\PresignedRequest::create($client, $cmd, '+10 minutes');
            $presignedUrl = (string) $requestPresigned->getUri();
            $headers = $requestPresigned->getHeaders();
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Unable to generate upload URL'], 500);
        }

        return response()->json([
            'upload_url' => $presignedUrl,
            'method' => 'PUT',
            'headers' => $headers,
            'key' => $key,
            'disk' => $disk,
            'expires_in' => 600,
        ]);
    }

    public function submitFinalWork(Request $request, string $reference): JsonResponse
    {
        $apiClient = $request->attributes->get('api_client');
        if (! $apiClient instanceof ApiClient) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $materialRequest = MaterialRequest::query()
            ->where('reference_code', $reference)
            ->where('api_client_id', $apiClient->id)
            ->first();

        if (! $materialRequest) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($materialRequest->requester_type !== 'trainee') {
            abort(403, 'Final work is only available for trainee requests.');
        }

        $validator = Validator::make($request->all(), [
            'link' => ['nullable', 'url', 'max:2048'],
            'file' => ['nullable', 'file'],
            'key' => ['nullable', 'string', 'max:1024'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        if (empty($data['link']) && ! $request->hasFile('file') && empty($data['key'])) {
            throw ValidationException::withMessages(['file' => ['Provide a link or upload a file.']]);
        }

        $filePath = null;
        $fileName = null;
        $fileMime = null;

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $maxBytes = (int) config('material-requests.final_work_max_bytes', 524288000);
            if ($file->getSize() > $maxBytes) {
                throw ValidationException::withMessages(['file' => ['File is too large.']]);
            }
            $allowed = config('material-requests.final_work_allowed_mimes', []);
            if (! empty($allowed) && ! in_array($file->getMimeType(), $allowed, true)) {
                throw ValidationException::withMessages(['file' => ['File type not allowed.']]);
            }
            $disk = 'r2';
            if (! config("filesystems.disks.{$disk}")) {
                $disk = 'local';
            }
            $path = $file->store('material-requests/final-work/'.$materialRequest->id, $disk);
            $filePath = $path;
            $fileName = $file->getClientOriginalName();
            $fileMime = $file->getMimeType();
        } elseif (! empty($data['key'])) {
            $filePath = $data['key'];
            $fileName = basename($data['key']);
        }

        $finalWork = MaterialRequestFinalWork::query()->create([
            'material_request_id' => $materialRequest->id,
            'link' => $data['link'] ?? null,
            'file_path' => $filePath,
            'file_name' => $fileName,
            'file_mime' => $fileMime,
            'note' => $data['note'] ?? null,
            'submitted_by_source' => $apiClient->name ?? 'api_client',
        ]);

        if ($materialRequest->final_work_status !== 'accepted') {
            $materialRequest->forceFill([
                'final_work_status' => 'submitted',
                'final_work_submitted_at' => now(),
            ])->save();
        }

        $this->webhooks->dispatchEvent($materialRequest->fresh(['apiClient']), 'final_work.submitted');

        return response()->json([
            'message' => 'Final work submitted',
            'final_work' => [
                'id' => $finalWork->id,
                'link' => $finalWork->link,
                'file_name' => $finalWork->file_name,
                'note' => $finalWork->note,
                'created_at' => $finalWork->created_at?->toIso8601String(),
            ],
            'final_work_status' => $materialRequest->fresh()->final_work_status,
        ], 201);
    }

    public function config(): JsonResponse
    {
        return response()->json([
            'requester_types' => config('material-requests.requester_types'),
            'fields' => config('material-requests.fields'),
            'labels' => config('material-requests.labels'),
            'bilingual_terms' => config('material-requests.bilingual_terms'),
            'consent_declaration' => config('material-requests.consent_declaration'),
            'cohorts' => Cohort::query()->orderByDesc('year')->orderBy('name')->get(['id', 'name', 'code', 'year']),
        ]);
    }

    /**
     * @param  list<?string>  $parts
     */
    private function composeAddress(array $parts): ?string
    {
        $parts = array_values(array_filter($parts, fn ($part) => filled($part)));

        return $parts ? implode(', ', $parts) : null;
    }
}
