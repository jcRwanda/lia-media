<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    public function log(string $action, ?Model $subject = null, array $properties = [], ?User $actor = null, string $actorType = 'user'): AuditLog
    {
        $request = request();
        $actor ??= $request?->user();

        return AuditLog::query()->create([
            'actor_id' => $actor?->id,
            'actor_type' => $actor ? $actorType : ($actorType === 'user' ? 'system' : $actorType),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            'properties' => $properties ?: null,
        ]);
    }
}
