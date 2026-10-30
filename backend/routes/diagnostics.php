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
        foreach (['public.users', 'cchia_m02.role_assignments', 'cchia_admissions.applications', 'cchia_admissions.application_submissions'] as $table) {
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
    $this->info('Secretos de recuperación vencidos eliminados: '.$count);

    return 0;
})->purpose('Elimina el contenido cifrado vencido y conserva la protección contra duplicados.');

Artisan::command('admissions:local-user {email} {--name=Usuario de pruebas} {--reviewer}', function (): int {
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
            foreach (['admissions.read', 'admissions.documents.read', 'admissions.review.start'] as $permission) {
                DB::table('cchia_m02.role_permissions')->insertOrIgnore(['role_code' => 'directorio', 'permission_code' => $permission]);
            }
            DB::table('cchia_m02.role_assignments')->insert([
                'id' => (string) Illuminate\Support\Str::uuid(), 'user_id' => $user->id,
                'role_code' => 'directorio', 'scope_id' => $scope, 'status' => 'active',
                'starts_at' => now(), 'accepted_at' => now(),
            ]);
        }
        DB::table('cchia_m02.authorization_audit_log')->insert([
            'id' => (string) Illuminate\Support\Str::uuid(), 'subject_user_id' => $user->id,
            'action' => 'development.local_user_created', 'entity_type' => 'user', 'entity_id' => $user->id,
            'metadata' => json_encode(['reviewer' => (bool) $this->option('reviewer')], JSON_THROW_ON_ERROR), 'occurred_at' => now(),
        ]);
    });
    $this->info('Cuenta local creada. No cambia la habilitación de la revisión institucional.');

    return 0;
})->purpose('Crea explícitamente una cuenta de prueba local, sin contraseñas predeterminadas.');
