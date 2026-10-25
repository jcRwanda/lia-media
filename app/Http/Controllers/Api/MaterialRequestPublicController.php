<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\MaterialRequestAdminNotificationMail;
use App\Mail\MaterialRequestConfirmationMail;
use App\Models\EquipmentItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\User;
use App\Services\MaterialRequestAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MaterialRequestPublicController extends Controller
{
    public function __construct(private MaterialRequestAuditService $audit) {}

    /**
     * Catalog of requestable items (available qty)
     */
    public function catalog(): JsonResponse
    {
        $items = EquipmentItem::query()
            ->orderBy('name')
            ->get()
            ->map(fn (EquipmentItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'category' => $item->category,
                'condition' => $item->condition,
                'available_qty' => $item->total_qty,
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

        $rules = [
            'requester_type' => ['required', Rule::in(config('material-requests.requester_types', ['client', 'trainee', 'alumni']))],
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:255'],
            'national_id' => ['nullable', 'string', 'max:100'],
            'province_res' => ['nullable', 'string', 'max:255'],
            'district_res' => ['nullable', 'string', 'max:255'],
            'sector_res' => ['nullable', 'string', 'max:255'],
            'cell_res' => ['nullable', 'string', 'max:255'],
            'residential_address' => ['nullable', 'string', 'max:500'],
            'emergency_contact_name' => ['required', 'string', 'max:255'],
            'emergency_contact_phone' => ['required', 'string', 'max:50'],
            'purpose_project_description' => ['required', 'string'],
            'project_province' => ['nullable', 'string', 'max:255'],
            'project_district' => ['nullable', 'string', 'max:255'],
            'project_sector' => ['nullable', 'string', 'max:255'],
            'project_cell' => ['nullable', 'string', 'max:255'],
            'project_location' => ['nullable', 'string', 'max:500'],
            'needed_from' => ['required', 'date'],
            'return_by' => ['required', 'date', 'after_or_equal:needed_from'],
            'terms_accepted' => ['required', 'accepted'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.equipment_item_id' => ['required', 'integer', 'exists:equipment_items,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
        ];

        $requesterType = $data['requester_type'] ?? 'client';
        if (in_array($requesterType, ['trainee', 'alumni'], true)) {
            $rules['program_cohort'] = ['nullable', 'string', 'max:255'];
            $rules['graduation_completion_year'] = ['nullable', 'string', 'max:10'];
            $rules['student_alumni_id'] = ['nullable', 'string', 'max:255'];
            $rules['project_title'] = ['nullable', 'string', 'max:255'];
            $rules['project_type'] = ['nullable', 'string', 'max:255'];
            $rules['expected_outcome'] = ['nullable', 'string'];
            $rules['portfolio_social_link'] = ['nullable', 'string', 'max:500'];
        }

        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        $materialRequest = DB::transaction(function () use ($validated) {
            $materialRequest = MaterialRequest::query()->create([
                'requester_type' => $validated['requester_type'],
                'full_name' => $validated['full_name'],
                'phone' => $validated['phone'],
                'email' => $validated['email'],
                'national_id' => $validated['national_id'] ?? null,
                'province_res' => $validated['province_res'] ?? null,
                'district_res' => $validated['district_res'] ?? null,
                'sector_res' => $validated['sector_res'] ?? null,
                'cell_res' => $validated['cell_res'] ?? null,
                'residential_address' => $validated['residential_address'] ?? null,
                'emergency_contact_name' => $validated['emergency_contact_name'],
                'emergency_contact_phone' => $validated['emergency_contact_phone'],
                'purpose_project_description' => $validated['purpose_project_description'],
                'project_province' => $validated['project_province'] ?? null,
                'project_district' => $validated['project_district'] ?? null,
                'project_sector' => $validated['project_sector'] ?? null,
                'project_cell' => $validated['project_cell'] ?? null,
                'project_location' => $validated['project_location'] ?? null,
                'needed_from' => $validated['needed_from'],
                'return_by' => $validated['return_by'],
                'terms_accepted' => true,
                'program_cohort' => $validated['program_cohort'] ?? null,
                'graduation_completion_year' => $validated['graduation_completion_year'] ?? null,
                'student_alumni_id' => $validated['student_alumni_id'] ?? null,
                'project_title' => $validated['project_title'] ?? null,
                'project_type' => $validated['project_type'] ?? null,
                'expected_outcome' => $validated['expected_outcome'] ?? null,
                'portfolio_social_link' => $validated['portfolio_social_link'] ?? null,
                'status' => 'submitted',
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

    public function config(): JsonResponse
    {
        return response()->json([
            'requester_types' => config('material-requests.requester_types'),
            'fields' => config('material-requests.fields'),
            'bilingual_terms' => config('material-requests.bilingual_terms'),
        ]);
    }
}
