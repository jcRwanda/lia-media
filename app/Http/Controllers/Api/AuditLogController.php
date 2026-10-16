<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $page = AuditLog::query()->with('actor:id,name')
            ->when($request->query('action'), fn ($q, $a) => $q->where('action', 'like', $a.'%'))
            ->when($request->query('subject_type'), fn ($q, $t) => $q->where('subject_type', $t))
            ->when($request->query('subject_id'), fn ($q, $id) => $q->where('subject_id', $id))
            ->orderByDesc('id')->paginate(50);

        return response()->json($page->through(fn (AuditLog $l) => [
            'id' => $l->id, 'action' => $l->action, 'actor' => $l->actor?->name, 'actor_type' => $l->actor_type,
            'subject_type' => $l->subject_type, 'subject_id' => $l->subject_id, 'ip' => $l->ip,
            'properties' => $l->properties, 'created_at' => $l->created_at?->toIso8601String(),
        ]));
    }
}
