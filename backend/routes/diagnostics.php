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
        foreach (['public.users', 'cchia_m02.role_assignments', 'cchia_admissions.applications'] as $table) {
            $present = DB::scalar('SELECT to_regclass(?)', [$table]) !== null;
            $this->line($table.': '.($present ? 'presente' : 'pendiente de migración'));
        }
        $this->line('Revisión institucional: '.(config('admissions.review_enabled') ? 'habilitada; requiere permisos efectivos' : 'deshabilitada'));
        $this->line('Almacenamiento: '.config('filesystems.disks.admissions-private.driver'));
        return 0;
    } catch (Throwable $exception) {
        $this->error('No fue posible comprobar la conexión. Revisar DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME y DB_PASSWORD en el entorno local.');
        $this->line('Tipo de error: '.get_class($exception));
        return 1;
    }
})->purpose('Comprueba versiones y tablas sin mostrar credenciales ni datos personales.');
