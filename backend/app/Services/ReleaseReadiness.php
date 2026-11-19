<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ReleaseReadiness
{
    public function checks(): array
    {
        $front = parse_url((string) config('admissions.frontend_url'));
        $api = parse_url((string) config('app.url'));
        $host = $front['host'] ?? '';
        $safe = $host !== '' && !in_array($host, ['localhost', '127.0.0.1', 'example.test'], true) && !str_ends_with($host, '.test');
        $checks = [
            'environment_production' => app()->environment('production'),
            'debug_disabled' => config('app.debug') === false,
            'https_same_origin' => ($front['scheme'] ?? '') === 'https' && ($api['scheme'] ?? '') === 'https'
                && $host === ($api['host'] ?? '') && ($front['port'] ?? 443) === ($api['port'] ?? 443) && $safe,
            'app_key_present' => is_string(config('app.key')) && strlen(config('app.key')) >= 32,
            'session_server_side' => in_array(config('session.driver'), ['file', 'database', 'redis'], true),
            'session_secure' => (bool) config('session.secure'),
            'session_http_only' => (bool) config('session.http_only'),
            'session_same_site' => in_array(config('session.same_site'), ['lax', 'strict'], true),
            'strict_sponsorship' => (bool) config('admissions.require_member_sponsors') && (bool) config('admissions.sponsorships_enabled'),
            'private_documents' => config('filesystems.disks.admissions-private.visibility') === 'private'
                && !config('filesystems.disks.admissions-private.serve', false),
            'scheduler_heartbeat' => app(CchiaOperations::class)->heartbeat()['ok'],
            'real_mail_transport' => in_array(config('mail.default'), ['smtp', 'ses', 'postmark', 'resend'], true),
        ];
        try {
            $version = (int) DB::scalar('SHOW server_version_num');
            $checks['postgresql_18'] = $version >= 180000 && $version < 190000;
            foreach (['appeals','assembly_resolutions','correction_requests','correction_submissions','document_revisions','correction_reviews','membership_registrations','notification_outbox','notification_retries'] as $table) {
                $checks['table_'.$table] = DB::scalar('SELECT to_regclass(?)', ['cchia_admissions.'.$table]) !== null;
            }
            $checks['non_test_database'] = !str_ends_with((string) config('database.connections.pgsql.database'), '_test');
        } catch (\Throwable) { $checks['database_reachable'] = false; }
        return $checks;
    }
}
