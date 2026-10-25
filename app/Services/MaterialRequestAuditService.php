<?php

namespace App\Services;

use App\Models\MaterialRequest;
use App\Models\MaterialRequestAudit;
use App\Models\User;

class MaterialRequestAuditService
{
    public function log(MaterialRequest $request, string $action, array $properties = [], ?User $actor = null, string $actorType = 'user'): MaterialRequestAudit
    {
        $req = request();

        $actor ??= $req?->user();

        return MaterialRequestAudit::query()->create([
            'material_request_id' => $request->id,
            'actor_id' => $actor?->id,
            'actor_type' => $actor ? $actorType : ($actorType === 'user' ? 'system' : $actorType),
            'action' => $action,
            'properties' => $properties ?: null,
            'ip' => $req?->ip(),
            'user_agent' => $req ? mb_substr((string) $req->userAgent(), 0, 255) : null,
        ]);
    }
}
