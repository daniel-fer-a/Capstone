<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE cchia_admissions.appeals (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    application_id uuid NOT NULL UNIQUE REFERENCES cchia_admissions.applications(id),
    reason text NOT NULL CHECK (btrim(reason) <> '' AND length(reason) <= 10000),
    requested_at timestamptz NOT NULL,
    request_hash varchar(64) NOT NULL CHECK (request_hash ~ '^[a-f0-9]{64}$')
);
CREATE TABLE cchia_admissions.assembly_resolutions (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    appeal_id uuid NOT NULL UNIQUE REFERENCES cchia_admissions.appeals(id),
    outcome varchar(20) NOT NULL CHECK (outcome IN ('approved', 'rejected')),
    reason text NOT NULL CHECK (btrim(reason) <> '' AND length(reason) <= 10000),
    meeting_date date NOT NULL,
    meeting_type varchar(20) NOT NULL CHECK (meeting_type IN ('ordinary', 'extraordinary')),
    minutes_reference varchar(255) NOT NULL CHECK (btrim(minutes_reference) <> ''),
    recorded_by_user_id varchar(36) NOT NULL REFERENCES public.users(id),
    recorded_at timestamptz NOT NULL
);
CREATE TABLE cchia_admissions.correction_requests (
    sequence_no bigint GENERATED ALWAYS AS IDENTITY UNIQUE,
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    application_id uuid NOT NULL REFERENCES cchia_admissions.applications(id),
    reason text NOT NULL CHECK (btrim(reason) <> '' AND length(reason) <= 10000),
    requested_kinds jsonb NOT NULL CHECK (jsonb_typeof(requested_kinds) = 'array' AND jsonb_array_length(requested_kinds) BETWEEN 1 AND 3),
    operation_hash varchar(64) NOT NULL UNIQUE CHECK (operation_hash ~ '^[a-f0-9]{64}$'),
    requested_by_user_id varchar(36) NOT NULL REFERENCES public.users(id),
    requested_at timestamptz NOT NULL
);
CREATE TABLE cchia_admissions.correction_submissions (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    correction_id uuid NOT NULL UNIQUE REFERENCES cchia_admissions.correction_requests(id),
    request_hash varchar(64) NOT NULL CHECK (request_hash ~ '^[a-f0-9]{64}$'),
    submitted_at timestamptz NOT NULL,
    UNIQUE(correction_id, id)
);
CREATE TABLE cchia_admissions.document_revisions (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    submission_id uuid NOT NULL REFERENCES cchia_admissions.correction_submissions(id),
    application_id uuid NOT NULL REFERENCES cchia_admissions.applications(id),
    kind varchar(30) NOT NULL CHECK (kind IN ('estatutos', 'vigencia', 'personeria', 'antecedentes')),
    version_no integer NOT NULL CHECK (version_no >= 2),
    disk varchar(50) NOT NULL CHECK (disk = 'admissions-private'),
    storage_key varchar(512) NOT NULL UNIQUE,
    original_name varchar(255) NOT NULL,
    mime_type varchar(100) NOT NULL CHECK (mime_type IN ('application/pdf', 'image/png', 'image/jpeg')),
    bytes integer NOT NULL CHECK (bytes > 0 AND bytes <= 5242880),
    sha256 varchar(64) NOT NULL CHECK (sha256 ~ '^[a-f0-9]{64}$'),
    uploaded_at timestamptz NOT NULL,
    UNIQUE(application_id, kind, version_no), UNIQUE(submission_id, kind)
);
CREATE TABLE cchia_admissions.correction_reviews (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    correction_id uuid NOT NULL UNIQUE REFERENCES cchia_admissions.correction_requests(id),
    submission_id uuid NOT NULL UNIQUE,
    outcome varchar(20) NOT NULL CHECK (outcome IN ('accepted', 'needs_changes')),
    reason text NOT NULL CHECK (btrim(reason) <> '' AND length(reason) <= 10000),
    reviewed_by_user_id varchar(36) NOT NULL REFERENCES public.users(id),
    reviewed_at timestamptz NOT NULL,
    FOREIGN KEY(correction_id, submission_id) REFERENCES cchia_admissions.correction_submissions(correction_id, id)
);
CREATE TABLE cchia_admissions.membership_registrations (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    application_id uuid NOT NULL UNIQUE REFERENCES cchia_admissions.applications(id),
    legal varchar(20) NOT NULL CHECK (legal IN ('natural', 'juridica')),
    identity_hash varchar(64) NOT NULL CHECK (identity_hash ~ '^[a-f0-9]{64}$'),
    register_reference varchar(255) NOT NULL UNIQUE CHECK (btrim(register_reference) <> ''),
    effective_date date NOT NULL,
    recorded_by_user_id varchar(36) NOT NULL REFERENCES public.users(id),
    recorded_at timestamptz NOT NULL,
    UNIQUE(legal, identity_hash)
);
DO $$ DECLARE t text; BEGIN
    FOREACH t IN ARRAY ARRAY['appeals','assembly_resolutions','correction_requests','correction_submissions','document_revisions','correction_reviews','membership_registrations'] LOOP
        EXECUTE format('CREATE TRIGGER evidence_immutable BEFORE UPDATE OR DELETE ON cchia_admissions.%I FOR EACH ROW EXECUTE FUNCTION cchia_admissions.prevent_evidence_update()', t);
    END LOOP;
END $$;
CREATE INDEX corrections_by_application ON cchia_admissions.correction_requests(application_id, requested_at);
CREATE INDEX revisions_by_application ON cchia_admissions.document_revisions(application_id, kind, version_no);

CREATE FUNCTION cchia_admissions.check_casework_insert() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE a cchia_admissions.applications%ROWTYPE; target uuid; c cchia_admissions.correction_requests%ROWTYPE;
BEGIN
    IF TG_TABLE_NAME = 'assembly_resolutions' THEN
        SELECT application_id INTO target FROM cchia_admissions.appeals WHERE id = NEW.appeal_id;
    ELSIF TG_TABLE_NAME IN ('correction_submissions', 'correction_reviews') THEN
        SELECT * INTO c FROM cchia_admissions.correction_requests WHERE id = NEW.correction_id;
        target := c.application_id;
    ELSE target := NEW.application_id; END IF;
    SELECT * INTO a FROM cchia_admissions.applications WHERE id = target FOR UPDATE;
    IF a.rules_version = '2026-10-28-pilot-2' THEN
        RAISE EXCEPTION 'Un piloto local no admite actuaciones institucionales' USING ERRCODE = '23514';
    END IF;
    IF TG_TABLE_NAME = 'appeals' AND (a.status <> 'rejected' OR NOT EXISTS (SELECT 1 FROM cchia_admissions.board_resolutions WHERE application_id = target AND outcome = 'rejected')) THEN
        RAISE EXCEPTION 'La apelación requiere rechazo inicial registrado' USING ERRCODE = '23514';
    ELSIF TG_TABLE_NAME IN ('correction_requests','correction_submissions','correction_reviews') THEN
        IF a.status <> 'under_review' THEN RAISE EXCEPTION 'La subsanación requiere revisión abierta' USING ERRCODE = '23514'; END IF;
        IF TG_TABLE_NAME = 'correction_requests' THEN
            IF EXISTS (SELECT 1 FROM cchia_admissions.correction_requests r WHERE r.application_id = target AND NOT EXISTS (SELECT 1 FROM cchia_admissions.correction_reviews v WHERE v.correction_id = r.id)) THEN
                RAISE EXCEPTION 'Ya existe una subsanación pendiente' USING ERRCODE = '23514';
            END IF;
            IF EXISTS (SELECT 1 FROM jsonb_array_elements_text(NEW.requested_kinds) k WHERE k NOT IN ('estatutos','vigencia','personeria','antecedentes'))
                OR (a.rules_version = '2026-10-29-staged-3' AND a.legal = 'juridica' AND NOT NEW.requested_kinds <@ '["estatutos","vigencia"]'::jsonb)
                OR (a.legal = 'natural' AND NEW.requested_kinds <> '["antecedentes"]'::jsonb) THEN
                RAISE EXCEPTION 'Tipos de documentos incompatibles con la modalidad' USING ERRCODE = '23514';
            END IF;
        END IF;
    ELSIF TG_TABLE_NAME = 'membership_registrations' THEN
        IF NEW.legal <> a.legal OR a.membership <> 'member' OR NOT (
            (a.status = 'approved' AND EXISTS (SELECT 1 FROM cchia_admissions.board_resolutions WHERE application_id = target AND outcome = 'approved'))
            OR EXISTS (SELECT 1 FROM cchia_admissions.appeals p JOIN cchia_admissions.assembly_resolutions r ON r.appeal_id = p.id WHERE p.application_id = target AND r.outcome = 'approved')
        ) THEN RAISE EXCEPTION 'El ingreso requiere una aprobación institucional registrada' USING ERRCODE = '23514'; END IF;
    END IF;
    RETURN NEW;
END;
$$;
DO $$ DECLARE t text; BEGIN
    FOREACH t IN ARRAY ARRAY['appeals','assembly_resolutions','correction_requests','correction_submissions','correction_reviews','membership_registrations'] LOOP
        EXECUTE format('CREATE TRIGGER valid_casework BEFORE INSERT ON cchia_admissions.%I FOR EACH ROW EXECUTE FUNCTION cchia_admissions.check_casework_insert()', t);
    END LOOP;
END $$;
CREATE FUNCTION cchia_admissions.check_correction_documents() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE sid uuid; c cchia_admissions.correction_requests%ROWTYPE; actual jsonb;
BEGIN
    IF TG_TABLE_NAME = 'correction_submissions' THEN sid := NEW.id; ELSE sid := NEW.submission_id; END IF;
    SELECT r.* INTO c FROM cchia_admissions.correction_requests r JOIN cchia_admissions.correction_submissions s ON s.correction_id = r.id WHERE s.id = sid;
    SELECT jsonb_agg(kind ORDER BY kind) INTO actual FROM cchia_admissions.document_revisions WHERE submission_id = sid;
    IF actual IS DISTINCT FROM (SELECT jsonb_agg(k ORDER BY k) FROM jsonb_array_elements_text(c.requested_kinds) k)
        OR EXISTS (SELECT 1 FROM cchia_admissions.document_revisions WHERE submission_id = sid AND application_id <> c.application_id) THEN
        RAISE EXCEPTION 'Los archivos deben coincidir exactamente con la subsanación solicitada' USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;
CREATE CONSTRAINT TRIGGER correction_submission_complete AFTER INSERT ON cchia_admissions.correction_submissions
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION cchia_admissions.check_correction_documents();
CREATE CONSTRAINT TRIGGER correction_revisions_complete AFTER INSERT ON cchia_admissions.document_revisions
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION cchia_admissions.check_correction_documents();
CREATE FUNCTION cchia_admissions.prevent_open_correction_decision() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.status IN ('approved','rejected') AND OLD.status IS DISTINCT FROM NEW.status AND EXISTS (
        SELECT 1 FROM cchia_admissions.correction_requests r WHERE r.application_id = NEW.id
        AND NOT EXISTS (SELECT 1 FROM cchia_admissions.correction_reviews v WHERE v.correction_id = r.id)
    ) THEN RAISE EXCEPTION 'Debe cerrarse la revisión de documentos antes de resolver' USING ERRCODE = '23514'; END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER no_decision_with_open_correction BEFORE UPDATE ON cchia_admissions.applications
FOR EACH ROW EXECUTE FUNCTION cchia_admissions.prevent_open_correction_decision();

ALTER TABLE cchia_admissions.notification_outbox DROP CONSTRAINT notification_outbox_kind_check;
ALTER TABLE cchia_admissions.notification_outbox DROP CONSTRAINT notification_outbox_check;
ALTER TABLE cchia_admissions.notification_outbox ADD COLUMN event_id uuid REFERENCES cchia_admissions.application_events(id);
ALTER TABLE cchia_admissions.notification_outbox ADD CONSTRAINT notification_kind CHECK (kind IN ('sponsor_invitation','application_received','application_event'));
ALTER TABLE cchia_admissions.notification_outbox ADD CONSTRAINT notification_target CHECK (
    (kind = 'sponsor_invitation' AND invitation_id IS NOT NULL AND application_id IS NULL AND event_id IS NULL)
    OR (kind = 'application_received' AND application_id IS NOT NULL AND invitation_id IS NULL AND event_id IS NULL)
    OR (kind = 'application_event' AND application_id IS NOT NULL AND invitation_id IS NULL AND event_id IS NOT NULL)
);
SQL);
        foreach ([
            'admissions.corrections.manage' => 'Solicitar y revisar subsanaciones documentales con versiones.',
            'admissions.appeals.record' => 'Registrar el acuerdo de la Asamblea sobre una apelación canalizada al Directorio.',
            'admissions.membership.formalize' => 'Registrar expresamente el ingreso aprobado en el registro de socios, sin otorgar roles.',
        ] as $code => $description) DB::table('cchia_m02.permissions')->insert(['code' => $code, 'description' => $description]);
    }

    public function down(): void
    {
        throw new RuntimeException('El expediente y sus versiones se conservan. Preparar una reversión revisada.');
    }
};
