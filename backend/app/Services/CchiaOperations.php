<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CchiaOperations
{
    public function summary(): array
    {
        $version = (int) DB::scalar('SHOW server_version_num');
        $tables = [];
        foreach (['applications','application_documents','document_revisions','membership_registrations','notification_outbox','notification_retries'] as $name) {
            $tables[$name] = DB::scalar('SELECT to_regclass(?)', ['cchia_admissions.'.$name]) !== null;
        }
        $mail = $tables['notification_outbox'] ? $this->mail() : null;
        $heartbeat = $this->heartbeat();
        return ['ok' => $version >= 180000 && $version < 190000 && !in_array(false, $tables, true)
                && ($mail['exhausted'] ?? 1) === 0 && $heartbeat['ok'],
            'postgresql_major' => (int) floor($version / 10000), 'tables' => $tables, 'mail' => $mail,
            'scheduler_heartbeat' => $heartbeat, 'review_enabled' => (bool) config('admissions.review_enabled'),
            'formalization_enabled' => (bool) config('admissions.formalization_enabled'),
            'notice' => 'El latido no certifica la entrega de correo, los respaldos, HTTPS ni la aceptación institucional.'];
    }

    public function mail(): array
    {
        $query = DB::table('cchia_admissions.notification_outbox');
        return ['processed' => (clone $query)->whereNotNull('sent_at')->count(),
            'pending' => (clone $query)->whereNull('sent_at')->whereNull('abandoned_at')->count(),
            'exhausted' => (clone $query)->whereNull('sent_at')->whereNotNull('abandoned_at')->whereNotNull('last_error_type')->count(),
            'omitted' => (clone $query)->whereNull('sent_at')->whereNotNull('abandoned_at')->whereNull('last_error_type')->count(),
            'transport' => config('mail.default'), 'real_transport' => !in_array(config('mail.default'), ['log','array'], true)];
    }

    public function pulse(): array
    {
        Cache::put('cchia:operations:scheduler:heartbeat', now()->getTimestamp(), now()->addMinutes(10));
        return ['ok' => true, 'recorded_at' => now()->toIso8601String()];
    }

    public function heartbeat(int $maximumAge = 180): array
    {
        $at = Cache::get('cchia:operations:scheduler:heartbeat');
        $age = is_int($at) ? now()->getTimestamp() - $at : null;
        return ['ok' => $age !== null && $age >= 0 && $age <= $maximumAge,
            'age_seconds' => $age, 'configured' => $at !== null, 'maximum_age_seconds' => $maximumAge];
    }

    public function permissions(): array
    {
        $rows = DB::table('cchia_m02.role_permissions')->where('permission_code', 'like', 'admissions.%')
            ->orderBy('role_code')->orderBy('permission_code')->get(['role_code','permission_code']);
        $unexpected = $rows->where('role_code', '<>', 'directorio')->values();
        $effective = DB::table('cchia_m02.role_assignments as ra')->join('cchia_m02.roles as r','r.code','=','ra.role_code')
            ->join('cchia_m02.access_scopes as s','s.id','=','ra.scope_id')->where('ra.status','active')->whereNull('ra.revoked_at')
            ->where('r.is_active',true)->where('r.default_scope','global')->where('s.kind','global')->where('ra.starts_at','<=',now())
            ->where(fn ($q) => $q->whereNull('ra.ends_at')->orWhere('ra.ends_at','>',now()))
            ->select('ra.role_code')->selectRaw('count(*) AS assignments')->groupBy('ra.role_code')->orderBy('ra.role_code')->get();
        return ['ok' => $unexpected->isEmpty(), 'role_permissions' => $rows, 'effective_assignment_counts' => $effective,
            'review_enabled' => (bool) config('admissions.review_enabled'),
            'notice' => 'Los permisos del catálogo requieren además rol, ámbito y vigencia. Este informe no concede permisos ni expone cuentas.'];
    }

    public function files(int $limit = 250, bool $full = false): array
    {
        $sources = ['application_documents','document_revisions'];
        $totals = [];
        foreach ($sources as $table) {
            if (DB::scalar('SELECT to_regclass(?)', ['cchia_admissions.'.$table]) === null) {
                return ['ok' => false, 'complete' => false, 'checked' => 0, 'issues' => [['table' => $table, 'reason' => 'missing_table']]];
            }
            $totals[$table] = DB::table('cchia_admissions.'.$table)->count();
        }
        $checked = 0; $issues = [];
        foreach ($sources as $table) {
            foreach (DB::table('cchia_admissions.'.$table)->orderBy('id')->cursor(['id','application_id','disk','storage_key','bytes','sha256']) as $row) {
                if (!$full && $checked >= $limit) break 2;
                $checked++;
                if ($row->disk !== 'admissions-private'
                    || !preg_match('~^[a-f0-9-]{36}/(?:revisions/)?[a-f0-9-]{36}\.(?:pdf|jpg|png)$~iD', $row->storage_key)
                    || !str_starts_with($row->storage_key, $row->application_id.'/')) {
                    $issues[] = ['table' => $table, 'id' => $row->id, 'reason' => 'unsafe_storage_reference'];
                    continue;
                }
                $stream = null;
                try {
                    $stream = Storage::disk($row->disk)->readStream($row->storage_key);
                    if (!is_resource($stream)) throw new \RuntimeException('unreadable');
                    $context = hash_init('sha256'); $bytes = 0;
                    while (!feof($stream)) {
                        $part = fread($stream, 65536);
                        if ($part === false || ($part === '' && !feof($stream))) throw new \RuntimeException('read_failed');
                        $bytes += strlen($part); hash_update($context, $part);
                    }
                    $hash = hash_final($context);
                    if ($bytes !== (int) $row->bytes || !hash_equals($row->sha256, $hash)) {
                        $issues[] = ['table' => $table, 'id' => $row->id, 'reason' => 'integrity_mismatch'];
                    }
                } catch (\Throwable) {
                    $issues[] = ['table' => $table, 'id' => $row->id, 'reason' => 'unreadable_or_missing'];
                } finally { if (is_resource($stream)) fclose($stream); }
            }
        }
        $complete = $checked === array_sum($totals);
        return ['ok' => $complete && $issues === [], 'complete' => $complete, 'expected' => array_sum($totals),
            'checked' => $checked, 'issues' => $issues, 'notice' => 'Solo se compara integridad y disponibilidad. No es una certificación antivirus.'];
    }
}
