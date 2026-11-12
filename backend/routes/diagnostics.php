<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('admissions:diagnose', function (): int {
    $this->line('PHP: '.PHP_VERSION);
    $this->line('Laravel: '.app()->version());
    if (DB::getDriverName() !== 'pgsql') {
        $this->error('La conexión debe ser pgsql.');
        return 1;
    }
    try {
        $version = (int) DB::scalar('SHOW server_version_num');
        $this->line('PostgreSQL server_version_num: '.$version);
        if ($version < 180000 || $version >= 190000) {
            $this->error('El objetivo acordado es PostgreSQL 18.x.');
            return 1;
        }
        $complete = true;
        foreach (['public.users', 'cchia_m02.role_assignments', 'cchia_admissions.applications', 'cchia_admissions.application_submissions',
            'cchia_admissions.intake_requests', 'cchia_admissions.sponsor_invitations', 'cchia_admissions.intake_events',
            'cchia_admissions.notification_outbox', 'cchia_admissions.board_resolutions',
            'cchia_admissions.appeals', 'cchia_admissions.assembly_resolutions', 'cchia_admissions.correction_requests',
            'cchia_admissions.correction_submissions', 'cchia_admissions.document_revisions', 'cchia_admissions.correction_reviews', 'cchia_admissions.membership_registrations', 'cchia_admissions.notification_retries'] as $table) {
            $present = DB::scalar('SELECT to_regclass(?)', [$table]) !== null;
            $this->line($table.': '.($present ? 'presente' : 'pendiente de migración'));
            $complete = $complete && $present;
        }
        $this->line('Revisión institucional: '.(config('admissions.review_enabled') ? 'habilitada; requiere permisos efectivos' : 'deshabilitada'));
        $this->line('Almacenamiento: '.config('filesystems.disks.admissions-private.driver'));
        $this->line('Dos patrocinios de socio requeridos: '.(config('admissions.require_member_sponsors') ? 'sí' : 'no; solo piloto local'));
        return $complete ? 0 : 1;
    } catch (Throwable $exception) {
        $this->error('No fue posible comprobar la conexión. Revisar DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME y DB_PASSWORD en el entorno local.');
        $this->line('Tipo de error: '.get_class($exception));
        return 1;
    }
})->purpose('Comprueba versiones y tablas sin mostrar credenciales ni datos personales.');

Artisan::command('admissions:purge-submission-secrets', function (): int {
    $count = DB::table('cchia_admissions.application_submissions')
        ->where('recoverable_until', '<=', now())->where('encrypted_tracking_token', '<>', '')
        ->update(['encrypted_tracking_token' => '']);
    $intakes = DB::table('cchia_admissions.intake_requests')->where('recovery_until', '<=', now())
        ->where('encrypted_access_token', '<>', '')->update(['encrypted_access_token' => '']);
    $this->info('Secretos de recuperación vencidos eliminados: '.$count);
    $this->info('Copias cifradas de acceso inicial eliminadas: '.$intakes);

    return 0;
})->purpose('Elimina el contenido cifrado vencido y conserva la protección contra duplicados.');

Artisan::command('admissions:local-user {email} {--name=Usuario de pruebas} {--reviewer} {--sponsor}', function (): int {
    if (!app()->environment(['local', 'testing'])) {
        $this->error('Este comando solo está disponible en un entorno local o de pruebas.');
        return 1;
    }
    $email = mb_strtolower(trim($this->argument('email')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255 || App\Models\User::query()->where('email', $email)->exists()) {
        $this->error('Usa un correo válido que no tenga una cuenta creada.');
        return 1;
    }
    $name = trim((string) $this->option('name'));
    if ($name === '' || mb_strlen($name) > 255) {
        $this->error('El nombre debe tener entre 1 y 255 caracteres.');
        return 1;
    }
    $password = $this->secret('Contraseña local: mínimo 12 caracteres con letras y números');
    if (!is_string($password) || mb_strlen($password) < 12 || strlen($password) > 72 || !preg_match('/\p{L}/u', $password) || !preg_match('/\d/', $password)) {
        $this->error('La contraseña no cumple los requisitos.');
        return 1;
    }
    DB::transaction(function () use ($email, $name, $password): void {
        $user = App\Models\User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
        if ($this->option('reviewer')) {
            $scope = DB::table('cchia_m02.access_scopes')->where('kind', 'global')->value('id');
            if (!$scope) {
                throw new RuntimeException('Falta el ámbito global de pruebas.');
            }
            foreach (['admissions.read', 'admissions.documents.read', 'admissions.review.start', 'admissions.decisions.record', 'admissions.corrections.manage', 'admissions.appeals.record', 'admissions.membership.formalize', 'admissions.membership.read', 'admissions.membership.export', 'admissions.notifications.manage'] as $permission) {
                DB::table('cchia_m02.role_permissions')->insertOrIgnore(['role_code' => 'directorio', 'permission_code' => $permission]);
            }
            DB::table('cchia_m02.role_assignments')->insert([
                'id' => (string) Illuminate\Support\Str::uuid(), 'user_id' => $user->id,
                'role_code' => 'directorio', 'scope_id' => $scope, 'status' => 'active',
                'starts_at' => now(), 'accepted_at' => now(),
            ]);
        }
        if ($this->option('sponsor')) {
            $scope = DB::table('cchia_m02.access_scopes')->where('kind', 'global')->value('id');
            if (!$scope) throw new RuntimeException('Falta el ámbito global de pruebas.');
            DB::table('cchia_m02.role_assignments')->insert([
                'id' => (string) Illuminate\Support\Str::uuid(), 'user_id' => $user->id,
                'role_code' => 'socio', 'scope_id' => $scope, 'status' => 'active',
                'starts_at' => now(), 'accepted_at' => now(),
            ]);
        }
        DB::table('cchia_m02.authorization_audit_log')->insert([
            'id' => (string) Illuminate\Support\Str::uuid(), 'subject_user_id' => $user->id,
            'action' => 'development.local_user_created', 'entity_type' => 'user', 'entity_id' => $user->id,
            'metadata' => json_encode(['reviewer' => (bool) $this->option('reviewer'), 'sponsor' => (bool) $this->option('sponsor')], JSON_THROW_ON_ERROR), 'occurred_at' => now(),
        ]);
    });
    $this->info('Cuenta local creada. No cambia la habilitación de la revisión institucional.');

    return 0;
})->purpose('Crea explícitamente una cuenta de prueba local, sin contraseñas predeterminadas.');

Artisan::command('admissions:send-notifications {--limit=25}', function (): int {
    $result = app(App\Services\NotificationOutbox::class)->process((int) $this->option('limit'));
    $this->info('Procesadas: '.$result['sent'].'; pendientes de reintento: '.$result['failed'].'; omitidas o agotadas: '.$result['abandoned']);
    return $result['failed'] > 0 ? 1 : 0;
})->purpose('Procesa avisos comprometidos en la base, sin incluir códigos privados de seguimiento.');

Illuminate\Support\Facades\Schedule::command('admissions:send-notifications --limit=25')->everyMinute()->withoutOverlapping(5);
Illuminate\Support\Facades\Schedule::command('admissions:purge-submission-secrets')->hourly()->withoutOverlapping();
Illuminate\Support\Facades\Schedule::command('cchia:ops heartbeat --json')->everyMinute()->withoutOverlapping(2);
Illuminate\Support\Facades\Schedule::command('workspace:send-invitations --limit=25')->everyMinute()->withoutOverlapping(5);

Artisan::command('admissions:notifications-status', function (): int {
    $table = DB::table('cchia_admissions.notification_outbox');
    $this->line('Procesadas por el transporte configurado: '.(clone $table)->whereNotNull('sent_at')->count());
    $this->line('Pendientes o en reintento: '.(clone $table)->whereNull('sent_at')->whereNull('abandoned_at')->count());
    $this->line('Omitidas o con intentos agotados: '.(clone $table)->whereNotNull('abandoned_at')->count());
    $this->line('Transporte: '.config('mail.default').'; log y array no entregan correos reales.');
    return 0;
})->purpose('Resume el procesamiento de avisos sin mostrar destinatarios ni códigos privados.');

Artisan::command('admissions:local-review-permissions {email}', function (): int {
    if (!app()->environment(['local', 'testing'])) {
        $this->error('Solo se permite en local o testing.');
        return 1;
    }
    $email = mb_strtolower(trim($this->argument('email')));
    $user = App\Models\User::query()->where('email', $email)->first();
    if (!$user || !app(App\Services\Model02Access::class)->effectiveGlobalAssignments($user->id, now())->where('ra.role_code', 'directorio')->exists()) {
        $this->error('La cuenta debe tener una asignación global vigente de Directorio.');
        return 1;
    }
    DB::transaction(function (): void {
        foreach (['admissions.read','admissions.documents.read','admissions.review.start','admissions.decisions.record','admissions.corrections.manage','admissions.appeals.record','admissions.membership.formalize', 'admissions.membership.read', 'admissions.membership.export', 'admissions.notifications.manage'] as $permission) {
            DB::table('cchia_m02.role_permissions')->insertOrIgnore(['role_code' => 'directorio', 'permission_code' => $permission]);
        }
        DB::table('cchia_m02.authorization_audit_log')->insert(['id' => (string) Illuminate\Support\Str::uuid(),
            'action' => 'development.review_permissions_configured', 'entity_type' => 'role', 'entity_id' => 'directorio',
            'metadata' => '{}', 'occurred_at' => now()]);
    });
    $this->info('Permisos de QA configurados para el rol Directorio del entorno local. No habilita las funciones por sí solo.');
    return 0;
})->purpose('Prepara expresamente los permisos de un rol de prueba existente; no se ejecuta en producción.');

Artisan::command('admissions:release-check', function (): int {
    $checks = app(App\Services\ReleaseReadiness::class)->checks();
    foreach ($checks as $name => $passed) $this->line(($passed ? 'OK ' : 'PENDIENTE ').$name);
    $this->line('Estas comprobaciones no sustituyen la aprobación de QA, HTTPS real, restauración de respaldos, correo recibido ni revisión institucional de permisos.');
    return in_array(false, $checks, true) ? 1 : 0;
})->purpose('Comprueba configuración mínima de liberación, sin desplegar, escribir datos ni mostrar credenciales.');
