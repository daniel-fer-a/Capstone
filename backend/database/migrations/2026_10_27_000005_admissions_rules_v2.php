<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reglas 2026-10-27-base-2 (decisiones del equipo tras la revisión de la maqueta):
 *  - Persona natural: nombres, apellidos y edad por separado.
 *  - Socio (natural o jurídica): dos patrocinadores obligatorios.
 *  - Persona jurídica: un solo documento obligatorio (estatutos o inicio de actividades).
 *
 * Las solicitudes ya guardadas con la regla anterior (rules_version = '2026-10-16-base-1')
 * se conservan y se siguen validando con la regla anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE cchia_admissions.applications
    ADD COLUMN natural_first_names varchar(150),
    ADD COLUMN natural_last_names varchar(150),
    ADD COLUMN natural_age smallint;

ALTER TABLE cchia_admissions.applications
    ADD CONSTRAINT applications_natural_person_detail CHECK (
        legal <> 'natural'
        OR rules_version = '2026-10-16-base-1'
        OR (natural_first_names IS NOT NULL AND btrim(natural_first_names) <> ''
            AND natural_last_names IS NOT NULL AND btrim(natural_last_names) <> ''
            AND natural_age IS NOT NULL AND natural_age BETWEEN 1 AND 120)
    ),
    ADD CONSTRAINT applications_juridica_without_person_detail CHECK (
        legal = 'natural'
        OR (natural_first_names IS NULL AND natural_last_names IS NULL AND natural_age IS NULL)
    );

CREATE OR REPLACE FUNCTION cchia_admissions.check_complete_application() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE target uuid; a cchia_admissions.applications%ROWTYPE; sponsor_count integer; needs_sponsors boolean;
BEGIN
    IF TG_TABLE_NAME = 'applications' THEN target := COALESCE(NEW.id, OLD.id);
    ELSE target := COALESCE(NEW.application_id, OLD.application_id); END IF;
    SELECT * INTO a FROM cchia_admissions.applications WHERE id = target;
    IF NOT FOUND THEN RETURN NULL; END IF;
    SELECT count(*) INTO sponsor_count FROM cchia_admissions.application_sponsors WHERE application_id = target;

    IF a.rules_version = '2026-10-16-base-1' THEN
        -- Regla anterior, sin cambios.
        IF a.legal = 'juridica' THEN
            IF (SELECT count(*) FROM cchia_admissions.application_documents WHERE application_id = target AND kind IN ('vigencia', 'personeria', 'estatutos')) <> 3
               OR sponsor_count <> 2 THEN
                RAISE EXCEPTION 'Faltan antecedentes o dos patrocinadores distintos' USING ERRCODE = '23514';
            END IF;
        ELSIF sponsor_count > 0 THEN
            RAISE EXCEPTION 'Esta modalidad no utiliza patrocinadores en la regla base' USING ERRCODE = '23514';
        END IF;
        RETURN NULL;
    END IF;

    IF a.legal = 'juridica'
       AND (SELECT count(*) FROM cchia_admissions.application_documents WHERE application_id = target AND kind = 'estatutos') <> 1 THEN
        RAISE EXCEPTION 'Falta el documento de estatutos o inicio de actividades' USING ERRCODE = '23514';
    END IF;
    needs_sponsors := (a.membership = 'member' OR a.legal = 'juridica');
    IF needs_sponsors AND sponsor_count <> 2 THEN
        RAISE EXCEPTION 'Se requieren dos socios patrocinadores distintos' USING ERRCODE = '23514';
    ELSIF NOT needs_sponsors AND sponsor_count > 0 THEN
        RAISE EXCEPTION 'Esta modalidad no utiliza patrocinadores' USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE cchia_admissions.applications
    DROP CONSTRAINT applications_juridica_without_person_detail,
    DROP CONSTRAINT applications_natural_person_detail,
    DROP COLUMN natural_age, DROP COLUMN natural_last_names, DROP COLUMN natural_first_names;

CREATE OR REPLACE FUNCTION cchia_admissions.check_complete_application() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE target uuid; a cchia_admissions.applications%ROWTYPE;
BEGIN
    IF TG_TABLE_NAME = 'applications' THEN target := COALESCE(NEW.id, OLD.id);
    ELSE target := COALESCE(NEW.application_id, OLD.application_id); END IF;
    SELECT * INTO a FROM cchia_admissions.applications WHERE id = target;
    IF NOT FOUND THEN RETURN NULL; END IF;
    IF a.legal = 'juridica' THEN
        IF (SELECT count(*) FROM cchia_admissions.application_documents WHERE application_id = target AND kind IN ('vigencia', 'personeria', 'estatutos')) <> 3
           OR (SELECT count(*) FROM cchia_admissions.application_sponsors WHERE application_id = target) <> 2 THEN
            RAISE EXCEPTION 'Faltan antecedentes o dos patrocinadores distintos' USING ERRCODE = '23514';
        END IF;
    ELSIF EXISTS (SELECT 1 FROM cchia_admissions.application_sponsors WHERE application_id = target) THEN
        RAISE EXCEPTION 'Esta modalidad no utiliza patrocinadores en la regla base' USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;
SQL);
    }
};
