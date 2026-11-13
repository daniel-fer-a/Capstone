<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CaseOperationsAccess
{
    public function authorize(User $user, string $permission, string $entityType, string $entityId): void
    {
        $access = app(Model02Access::class);
        $allowed = $access->reviewerHasPermission($user->id, 'admissions.read') && $access->reviewerHasPermission($user->id, $permission);
        $this->audit($user, $permission, $entityType, $entityId, ['result' => $allowed ? 'allowed' : 'denied']);
        abort_unless($allowed, 403);
    }

    public function audit(User $user, string $action, string $entityType, string $entityId, array $metadata = []): void
    {
        DB::table('cchia_m02.authorization_audit_log')->insert([
            'id' => (string) Str::uuid(), 'actor_user_id' => $user->id, 'action' => $action,
            'entity_type' => $entityType, 'entity_id' => $entityId,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'occurred_at' => now(),
        ]);
    }
}
