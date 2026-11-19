<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE cchia_admissions.intake_requests (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    reference varchar(40) NOT NULL UNIQUE,
    access_token_hash varchar(64) NOT NULL CHECK (access_token_hash ~ '^[a-f0-9]{64}$'),
    encrypted_access_token text NOT NULL,
    recovery_until timestamptz NOT NULL,
    submission_key_hash varchar(64) NOT NULL UNIQUE,
    request_hash varchar(64) NOT NULL,
    legal varchar(20) NOT NULL CHECK (legal IN ('natural', 'juridica')),
    encrypted_payload text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE cchia_admissions.sponsor_invitations (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    intake_id uuid NOT NULL REFERENCES cchia_admissions.intake_requests(id),
    position smallint NOT NULL CHECK (position IN (1, 2)),
    email varchar(255) NOT NULL CHECK (email = lower(email)),
    target_user_id varchar(36) REFERENCES public.users(id),
    state varchar(20) NOT NULL DEFAULT 'pending' CHECK (state IN ('pending', 'accepted', 'declined')),
    decided_by_user_id varchar(36) REFERENCES public.users(id),
    role_assignment_id uuid REFERENCES cchia_m02.role_assignments(id),
    decided_at timestamptz,
    superseded_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    CHECK ((state = 'pending' AND decided_at IS NULL AND decided_by_user_id IS NULL AND role_assignment_id IS NULL)
        OR (state <> 'pending' AND decided_at IS NOT NULL AND decided_by_user_id IS NOT NULL
            AND role_assignment_id IS NOT NULL AND target_user_id = decided_by_user_id))
);
CREATE UNIQUE INDEX current_sponsor_position ON cchia_admissions.sponsor_invitations(intake_id, position) WHERE superseded_at IS NULL;
CREATE UNIQUE INDEX current_sponsor_email ON cchia_admissions.sponsor_invitations(intake_id, email) WHERE superseded_at IS NULL;
CREATE INDEX assigned_sponsor_invitations ON cchia_admissions.sponsor_invitations(target_user_id, created_at) WHERE superseded_at IS NULL;

CREATE TABLE cchia_admissions.intake_events (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    intake_id uuid NOT NULL REFERENCES cchia_admissions.intake_requests(id),
    actor_user_id varchar(36) REFERENCES public.users(id),
    actor_kind varchar(20) NOT NULL CHECK (actor_kind IN ('applicant', 'member', 'system')),
    action varchar(80) NOT NULL,
    metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
    occurred_at timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER intake_events_append_only BEFORE UPDATE OR DELETE ON cchia_admissions.intake_events
FOR EACH ROW EXECUTE FUNCTION cchia_admissions.prevent_event_change();

ALTER TABLE cchia_admissions.applications ADD COLUMN intake_id uuid UNIQUE REFERENCES cchia_admissions.intake_requests(id);
ALTER TABLE cchia_admissions.applications ADD CONSTRAINT staged_natural_identity CHECK (
    rules_version <> '2026-10-29-staged-3' OR legal <> 'natural'
    OR (natural_given_names IS NOT NULL AND btrim(natural_given_names) <> ''
        AND natural_family_names IS NOT NULL AND btrim(natural_family_names) <> ''
        AND natural_age IS NOT NULL AND region IS NOT NULL AND btrim(region) <> ''
        AND commune IS NOT NULL AND btrim(commune) <> '')
);
ALTER TABLE cchia_admissions.application_sponsors
    ADD COLUMN invitation_id uuid UNIQUE REFERENCES cchia_admissions.sponsor_invitations(id),
    ADD COLUMN confirmed_at timestamptz;

CREATE TABLE cchia_admissions.notification_outbox (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    kind varchar(30) NOT NULL CHECK (kind IN ('sponsor_invitation', 'application_received')),
    deduplication_key varchar(128) NOT NULL UNIQUE,
    invitation_id uuid REFERENCES cchia_admissions.sponsor_invitations(id),
    application_id uuid REFERENCES cchia_admissions.applications(id),
    attempts smallint NOT NULL DEFAULT 0 CHECK (attempts BETWEEN 0 AND 5),
    available_at timestamptz NOT NULL DEFAULT now(),
    locked_at timestamptz,
    sent_at timestamptz,
    abandoned_at timestamptz,
    last_error_type varchar(255),
    created_at timestamptz NOT NULL DEFAULT now(),
    CHECK ((kind = 'sponsor_invitation' AND invitation_id IS NOT NULL AND application_id IS NULL)
        OR (kind = 'application_received' AND application_id IS NOT NULL AND invitation_id IS NULL))
);

CREATE OR REPLACE FUNCTION cchia_admissions.check_complete_application() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE target uuid; a cchia_admissions.applications%ROWTYPE; sponsor_count integer;
BEGIN
    IF TG_TABLE_NAME = 'applications' THEN target := COALESCE(NEW.id, OLD.id);
    ELSE target := COALESCE(NEW.application_id, OLD.application_id); END IF;
    SELECT * INTO a FROM cchia_admissions.applications WHERE id = target;
    IF NOT FOUND THEN RETURN NULL; END IF;
    SELECT count(*) INTO sponsor_count FROM cchia_admissions.application_sponsors WHERE application_id = target;
    IF a.rules_version = '2026-10-29-staged-3' THEN
        IF a.membership <> 'member' OR a.intake_id IS NULL OR sponsor_count <> 2 THEN
            RAISE EXCEPTION 'La solicitud requiere su etapa privada y dos confirmaciones' USING ERRCODE = '23514';
        END IF;
        IF (SELECT count(*) FROM cchia_admissions.application_sponsors s
            JOIN cchia_admissions.sponsor_invitations i ON i.id = s.invitation_id
            WHERE s.application_id = target AND i.intake_id = a.intake_id
              AND i.state = 'accepted' AND i.superseded_at IS NULL
              AND i.decided_by_user_id = s.sponsor_user_id AND i.position = s.position
              AND s.confirmed_at = i.decided_at AND i.decided_at <= a.submitted_at) <> 2 THEN
            RAISE EXCEPTION 'Falta la aceptación explícita de ambos socios' USING ERRCODE = '23514';
        END IF;
        IF a.legal = 'juridica' AND ((SELECT count(*) FROM cchia_admissions.application_documents
            WHERE application_id = target AND kind IN ('estatutos', 'vigencia')) <> 2
            OR (SELECT count(*) FROM cchia_admissions.application_documents WHERE application_id = target) <> 2) THEN
            RAISE EXCEPTION 'La persona jurídica requiere estatutos y vigencia' USING ERRCODE = '23514';
        END IF;
        RETURN NULL;
    END IF;
    IF a.rules_version = '2026-10-27-base-2' THEN
        IF a.legal = 'juridica' AND (SELECT count(*) FROM cchia_admissions.application_documents
            WHERE application_id = target AND kind = 'estatutos') <> 1 THEN
            RAISE EXCEPTION 'Faltan los antecedentes de la regla histórica' USING ERRCODE = '23514';
        END IF;
        IF ((a.membership = 'member' OR a.legal = 'juridica') AND sponsor_count <> 2)
           OR (a.membership = 'collaborator' AND a.legal = 'natural' AND sponsor_count <> 0) THEN
            RAISE EXCEPTION 'Patrocinios incompatibles con la regla histórica' USING ERRCODE = '23514';
        END IF;
        RETURN NULL;
    END IF;
    IF a.legal = 'juridica' THEN
        IF (SELECT count(*) FROM cchia_admissions.application_documents WHERE application_id = target
            AND kind IN ('vigencia', 'personeria', 'estatutos')) <> 3 OR sponsor_count <> 2 THEN
            RAISE EXCEPTION 'Faltan antecedentes o dos patrocinadores distintos' USING ERRCODE = '23514';
        END IF;
    ELSIF a.membership = 'member' THEN
        IF (a.rules_version = '2026-10-28-member-2' AND sponsor_count <> 2) OR sponsor_count NOT IN (0, 2) THEN
            RAISE EXCEPTION 'Se requieren dos socios patrocinadores distintos' USING ERRCODE = '23514';
        END IF;
    ELSIF sponsor_count <> 0 THEN
        RAISE EXCEPTION 'Esta modalidad no utiliza patrocinadores' USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;

CREATE FUNCTION cchia_admissions.protect_sponsorship_consent() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Las invitaciones se conservan como evidencia' USING ERRCODE = '23514';
    END IF;
    IF OLD.intake_id IS DISTINCT FROM NEW.intake_id OR OLD.email IS DISTINCT FROM NEW.email
       OR OLD.position IS DISTINCT FROM NEW.position OR OLD.target_user_id IS DISTINCT FROM NEW.target_user_id
       OR OLD.created_at IS DISTINCT FROM NEW.created_at
       OR (OLD.state <> 'pending' AND (OLD.state IS DISTINCT FROM NEW.state
           OR OLD.decided_at IS DISTINCT FROM NEW.decided_at
           OR OLD.decided_by_user_id IS DISTINCT FROM NEW.decided_by_user_id
           OR OLD.role_assignment_id IS DISTINCT FROM NEW.role_assignment_id))
       OR (OLD.superseded_at IS NOT NULL AND OLD.superseded_at IS DISTINCT FROM NEW.superseded_at) THEN
        RAISE EXCEPTION 'La confirmación de patrocinio conserva su identidad e historial' USING ERRCODE = '23514';
    END IF;
    IF NEW.superseded_at IS NOT NULL AND EXISTS (SELECT 1 FROM cchia_admissions.applications WHERE intake_id = OLD.intake_id) THEN
        RAISE EXCEPTION 'Los patrocinios enviados no se sustituyen' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER sponsorship_consent_evidence BEFORE UPDATE OR DELETE ON cchia_admissions.sponsor_invitations
FOR EACH ROW EXECUTE FUNCTION cchia_admissions.protect_sponsorship_consent();

CREATE FUNCTION cchia_admissions.protect_intake_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'La etapa de patrocinio conserva sus antecedentes' USING ERRCODE = '23514';
    END IF;
    IF OLD.id IS DISTINCT FROM NEW.id OR OLD.reference IS DISTINCT FROM NEW.reference
       OR OLD.access_token_hash IS DISTINCT FROM NEW.access_token_hash OR OLD.legal IS DISTINCT FROM NEW.legal
       OR OLD.encrypted_payload IS DISTINCT FROM NEW.encrypted_payload OR OLD.request_hash IS DISTINCT FROM NEW.request_hash
       OR OLD.submission_key_hash IS DISTINCT FROM NEW.submission_key_hash OR OLD.recovery_until IS DISTINCT FROM NEW.recovery_until
       OR OLD.created_at IS DISTINCT FROM NEW.created_at
       OR (OLD.encrypted_access_token IS DISTINCT FROM NEW.encrypted_access_token AND NEW.encrypted_access_token <> '') THEN
        RAISE EXCEPTION 'Los antecedentes patrocinados se conservan sin cambios' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER intake_identity_evidence BEFORE UPDATE OR DELETE ON cchia_admissions.intake_requests
FOR EACH ROW EXECUTE FUNCTION cchia_admissions.protect_intake_identity();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('La migración conserva solicitudes y consentimientos. La reversión requiere un procedimiento revisado.');
    }
};
