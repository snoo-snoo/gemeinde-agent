<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogger
{
    public function log(string $action, string $entityType, ?int $entityId, array $metadata = [], ?int $userId = null): void
    {
        $request = app(Request::class);
        AuditLog::query()->create([
            'user_id' => $userId ?? $request->user()?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'metadata' => $this->scrub($metadata),
            'ip' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    private function scrub(array $metadata): array
    {
        $blocked = ['secret', 'password', 'api_key', 'token', 'authorization'];
        $clean = [];
        foreach ($metadata as $key => $value) {
            if (in_array(strtolower((string) $key), $blocked, true)) {
                continue;
            }
            $clean[$key] = is_array($value) ? $this->scrub($value) : $value;
        }

        return $clean;
    }
}
