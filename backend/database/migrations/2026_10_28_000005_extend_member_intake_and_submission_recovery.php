<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE cchia_admissions.applications
    ADD COLUMN IF NOT EXISTS natural_given_names varchar(120),
    ADD COLUMN IF NOT EXISTS natural_family_names varchar(120),
    ADD COLUMN IF NOT EXISTS natural_age smallint,
    ADD COLUMN IF NOT EXISTS region varchar(120),
    ADD COLUMN IF NOT EXISTS commune varchar(120),
    ADD CONSTRAINT natural_age_non_negative CHECK (natural_age IS NULL OR natural_age >= 0),
    ADD CONSTRAINT member_v2_identity CHECK (
        rules_version NOT IN ('2026-10-28-member-2', '2026-10-28-pilot-2')
        OR legal <> 'natural' OR membership <> 'member'
        OR (natural_given_names IS NOT NULL AND btrim(natural_given_names) <> ''
            AND natural_family_names IS NOT NULL AND btrim(natural_family_names) <> ''
            AND natural_age IS NOT NULL
            AND region IS NOT NULL AND btrim(region) <> ''
            AND commune IS NOT NULL AND btrim(commune) <> '')
    );

CREATE TABLE cchia_admissions.application_submissions (
    key_hash varchar(64) PRIMARY KEY CHECK (key_hash ~ '^[a-f0-9]{64}$'),
    request_hash varchar(64) NOT NULL CHECK (request_hash ~ '^[a-f0-9]{64}$'),
    application_id uuid NOT NULL UNIQUE REFERENCES cchia_admissions.applications(id),
    encrypted_tracking_token text NOT NULL,
    created_at timestamptz NOT NULL,
    recoverable_until timestamptz NOT NULL CHECK (recoverable_until > created_at)
);

CREATE OR REPLACE FUNCTION cchia_admissions.check_complete_application() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE target uuid; a cchia_admissions.applications%ROWTYPE; sponsor_count integer;
BEGIN
    IF TG_TABLE_NAME = 'applications' THEN target := COALESCE(NEW.id, OLD.id);
    ELSE target := COALESCE(NEW.application_id, OLD.application_id); END IF;
    SELECT * INTO a FROM cchia_admissions.applications WHERE id = target;
    IF NOT FOUND THEN RETURN NULL; END IF;
    SELECT count(*) INTO sponsor_count FROM cchia_admissions.application_sponsors WHERE application_id = target;
    IF a.legal = 'juridica' THEN
        IF (SELECT count(*) FROM cchia_admissions.application_documents
            WHERE application_id = target AND kind IN ('vigencia', 'personeria', 'estatutos')) <> 3
            OR sponsor_count <> 2 THEN
            RAISE EXCEPTION 'Faltan antecedentes o dos patrocinadores distintos' USING ERRCODE = '23514';
        END IF;
    ELSIF a.membership = 'member' THEN
        IF (a.rules_version = '2026-10-28-member-2' AND sponsor_count <> 2)
            OR sponsor_count NOT IN (0, 2) THEN
            RAISE EXCEPTION 'Se requieren dos socios patrocinadores distintos' USING ERRCODE = '23514';
        END IF;
    ELSIF sponsor_count <> 0 THEN
        RAISE EXCEPTION 'Esta modalidad no utiliza patrocinadores en la regla base' USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;
SQL);
        DB::table('cchia_m02.permissions')->insert([
            'code' => 'admissions.review.start',
            'description' => 'Iniciar explícitamente una revisión de ingreso, con trazabilidad.',
        ]);
    }

    public function down(): void
    {
        throw new RuntimeException('Esta migración conserva datos históricos. Preparar una reversión revisada; no elimina datos automáticamente.');
    }
};
