<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Esta base requiere PostgreSQL 18.x.');
        }
        $version = (int) DB::scalar('SHOW server_version_num');
        if ($version < 180000 || $version >= 190000) {
            throw new RuntimeException('Esta base requiere PostgreSQL 18.x.');
        }
        foreach (['users', 'companies', 'commissions', 'resources', 'personal_access_tokens', 'password_reset_tokens'] as $table) {
            if (Schema::hasTable('public.'.$table)) {
                throw new RuntimeException('Instalación aislada: ya existe public.'.$table.'. No se adapta una base existente automáticamente.');
            }
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE public.users (
    id varchar(36) PRIMARY KEY DEFAULT gen_random_uuid()::text,
    name varchar(255) NOT NULL,
    email varchar(255) NOT NULL UNIQUE CHECK (email = lower(email)),
    email_verified_at timestamptz,
    password varchar(255) NOT NULL,
    remember_token varchar(100),
    created_at timestamptz,
    updated_at timestamptz
);
-- Contratos mínimos para las FK del Modelo 02; no son módulos funcionales.
CREATE TABLE public.companies (
    id varchar(36) PRIMARY KEY DEFAULT gen_random_uuid()::text,
    name varchar(255) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE public.commissions (
    id varchar(36) PRIMARY KEY DEFAULT gen_random_uuid()::text,
    name varchar(255) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE public.resources (
    id varchar(36) PRIMARY KEY DEFAULT gen_random_uuid()::text,
    title varchar(255) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE public.password_reset_tokens (
    email varchar(255) PRIMARY KEY,
    token varchar(255) NOT NULL,
    created_at timestamptz
);
CREATE TABLE public.personal_access_tokens (
    id bigserial PRIMARY KEY,
    tokenable_type varchar(255) NOT NULL,
    tokenable_id varchar(36) NOT NULL,
    name text NOT NULL,
    token varchar(64) NOT NULL UNIQUE,
    abilities text,
    last_used_at timestamptz,
    expires_at timestamptz,
    created_at timestamptz,
    updated_at timestamptz
);
CREATE INDEX personal_access_tokens_owner ON public.personal_access_tokens(tokenable_type, tokenable_id);
SQL);
    }

    public function down(): void
    {
        foreach (['personal_access_tokens', 'password_reset_tokens', 'resources', 'commissions', 'companies', 'users'] as $table) {
            Schema::drop('public.'.$table);
        }
    }
};
