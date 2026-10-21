<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class Model02Access
{
    public function effectiveGlobalAssignments(string $userId, CarbonInterface $at): Builder
    {
        return DB::table('cchia_m02.role_assignments as ra')
            ->join('cchia_m02.roles as r', 'r.code', '=', 'ra.role_code')
            ->join('cchia_m02.access_scopes as s', 's.id', '=', 'ra.scope_id')
            ->where('ra.user_id', $userId)->where('ra.status', 'active')
            ->where('r.is_active', true)->where('r.default_scope', 'global')->where('s.kind', 'global')
            ->whereNull('ra.revoked_at')->where('ra.starts_at', '<=', $at)
            ->where(fn (Builder $q) => $q->whereNull('ra.ends_at')->orWhere('ra.ends_at', '>', $at));
    }

    public function reviewerHasPermission(string $userId, string $permission): bool
    {
        if (!config('admissions.review_enabled')) {
            return false;
        }

        return $this->effectiveGlobalAssignments($userId, now())
            ->join('cchia_m02.role_permissions as rp', 'rp.role_code', '=', 'ra.role_code')
            ->where('ra.role_code', 'directorio')->where('rp.permission_code', $permission)->exists();
    }
}
