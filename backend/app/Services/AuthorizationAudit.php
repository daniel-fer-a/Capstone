<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuthorizationAudit
{
    public function record(User $actor, string $action, string $entityId, string $result): void
    {
        DB::table('cchia_m02.authorization_audit_log')->insert([
            'id' => (string) Str::uuid(), 'actor_user_id' => $actor->id,
            'action' => $action, 'entity_type' => 'admission_application', 'entity_id' => $entityId,
            'metadata' => json_encode(['result' => $result], JSON_THROW_ON_ERROR), 'occurred_at' => now(),
        ]);
    }
}
