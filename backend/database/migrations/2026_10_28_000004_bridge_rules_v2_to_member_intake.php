<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Puente entre la migración 2026_10_27_000005_admissions_rules_v2 (ya aplicada en algunos
 * entornos) y 2026_10_28_000005_extend_member_intake_and_submission_recovery.
 *
 * Ambas agregan nombres, apellidos y edad con nombres de columna distintos. Esta migración
 * unifica en los nombres del 28/10 (natural_given_names, natural_family_names), conserva los
 * datos existentes y retira las restricciones del 27/10 que ya no corresponden.
 *
 * En una base nueva se ejecuta después de la del 27/10, así que el resultado es el mismo.
 * Es idempotente: si las columnas del 27/10 no existen, no hace nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE cchia_admissions.applications
    DROP CONSTRAINT IF EXISTS applications_natural_person_detail,
    DROP CONSTRAINT IF EXISTS applications_juridica_without_person_detail;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.columns
               WHERE table_schema = 'cchia_admissions' AND table_name = 'applications' AND column_name = 'natural_first_names') THEN
        ALTER TABLE cchia_admissions.applications RENAME COLUMN natural_first_names TO natural_given_names;
        ALTER TABLE cchia_admissions.applications RENAME COLUMN natural_last_names TO natural_family_names;
        ALTER TABLE cchia_admissions.applications
            ALTER COLUMN natural_given_names TYPE varchar(120),
            ALTER COLUMN natural_family_names TYPE varchar(120);
    END IF;
END $$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Migración de reconciliación: no se revierte automáticamente.');
    }
};
