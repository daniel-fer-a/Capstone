CREATE SCHEMA cchia_admissions;

CREATE TABLE cchia_admissions.applications (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    reference varchar(40) NOT NULL UNIQUE,
    tracking_token_hash varchar(64) NOT NULL CHECK (tracking_token_hash ~ '^[a-f0-9]{64}$'),
    membership varchar(20) NOT NULL CHECK (membership IN ('member', 'collaborator')),
    legal varchar(20) NOT NULL CHECK (legal IN ('natural', 'juridica')),
    natural_name varchar(255),
    natural_identifier varchar(80),
    organization_name varchar(255),
    organization_identifier varchar(80),
    representative_name varchar(255),
    representative_identifier varchar(80),
    email varchar(255) NOT NULL CHECK (email = lower(email) AND btrim(email) <> ''),
    address varchar(500) NOT NULL CHECK (btrim(address) <> ''),
    ai_activity text NOT NULL CHECK (btrim(ai_activity) <> '' AND length(ai_activity) <= 10000),
    status varchar(20) NOT NULL DEFAULT 'received' CHECK (status IN ('received', 'under_review', 'approved', 'rejected')),
    rules_version varchar(60) NOT NULL,
    declaration_accepted_at timestamptz NOT NULL,
    submitted_at timestamptz NOT NULL DEFAULT now(),
    decision_at timestamptz,
    decision_by_user_id varchar(36) REFERENCES public.users(id),
    decision_reason text,
    CHECK (
        (legal = 'natural' AND natural_name IS NOT NULL AND btrim(natural_name) <> ''
         AND natural_identifier IS NOT NULL AND btrim(natural_identifier) <> ''
         AND organization_name IS NULL AND organization_identifier IS NULL
         AND representative_name IS NULL AND representative_identifier IS NULL)
        OR
        (legal = 'juridica' AND organization_name IS NOT NULL AND btrim(organization_name) <> ''
         AND organization_identifier IS NOT NULL AND btrim(organization_identifier) <> ''
         AND representative_name IS NOT NULL AND btrim(representative_name) <> ''
         AND representative_identifier IS NOT NULL AND btrim(representative_identifier) <> ''
         AND natural_name IS NULL AND natural_identifier IS NULL)
    ),
    CHECK (
        (status IN ('received', 'under_review') AND decision_at IS NULL AND decision_by_user_id IS NULL AND decision_reason IS NULL)
        OR
        (status IN ('approved', 'rejected') AND decision_at IS NOT NULL AND decision_by_user_id IS NOT NULL
         AND decision_reason IS NOT NULL AND btrim(decision_reason) <> '' AND decision_at >= submitted_at)
    )
);
CREATE INDEX applications_queue ON cchia_admissions.applications(status, submitted_at);

CREATE TABLE cchia_admissions.application_documents (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    application_id uuid NOT NULL REFERENCES cchia_admissions.applications(id),
    kind varchar(30) NOT NULL CHECK (kind IN ('vigencia', 'personeria', 'estatutos', 'antecedentes')),
    disk varchar(50) NOT NULL CHECK (disk = 'admissions-private'),
    storage_key varchar(512) NOT NULL UNIQUE,
    original_name varchar(255) NOT NULL,
    mime_type varchar(100) NOT NULL CHECK (mime_type IN ('application/pdf', 'image/png', 'image/jpeg')),
    bytes integer NOT NULL CHECK (bytes > 0 AND bytes <= 5242880),
    sha256 varchar(64) NOT NULL CHECK (sha256 ~ '^[a-f0-9]{64}$'),
    uploaded_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE(application_id, kind)
);

CREATE TABLE cchia_admissions.application_sponsors (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    application_id uuid NOT NULL REFERENCES cchia_admissions.applications(id),
    sponsor_user_id varchar(36) NOT NULL REFERENCES public.users(id),
    role_assignment_id uuid NOT NULL REFERENCES cchia_m02.role_assignments(id),
    position smallint NOT NULL CHECK (position IN (1, 2)),
    checked_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE(application_id, sponsor_user_id),
    UNIQUE(application_id, position)
);

CREATE TABLE cchia_admissions.application_events (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    application_id uuid NOT NULL REFERENCES cchia_admissions.applications(id),
    actor_user_id varchar(36) REFERENCES public.users(id),
    actor_kind varchar(30) NOT NULL CHECK (actor_kind IN ('applicant', 'institutional', 'system')),
    action varchar(80) NOT NULL,
    outcome varchar(30) NOT NULL,
    metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
    occurred_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX application_events_history ON cchia_admissions.application_events(application_id, occurred_at);

CREATE FUNCTION cchia_admissions.check_sponsor_assignment() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM cchia_m02.role_assignments ra
        JOIN cchia_m02.roles r ON r.code = ra.role_code
        JOIN cchia_m02.access_scopes s ON s.id = ra.scope_id
        WHERE ra.id = NEW.role_assignment_id AND ra.user_id = NEW.sponsor_user_id
          AND ra.role_code = 'socio' AND ra.status = 'active' AND ra.revoked_at IS NULL
          AND r.is_active AND r.default_scope = 'global' AND s.kind = 'global'
          AND ra.starts_at <= NEW.checked_at AND (ra.ends_at IS NULL OR ra.ends_at > NEW.checked_at)
    ) THEN
        RAISE EXCEPTION 'El patrocinio no corresponde a una asignación de socio vigente' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER valid_sponsor_assignment BEFORE INSERT ON cchia_admissions.application_sponsors
FOR EACH ROW EXECUTE FUNCTION cchia_admissions.check_sponsor_assignment();

CREATE FUNCTION cchia_admissions.prevent_evidence_update() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'El antecedente confirmado no se modifica; requiere un flujo de sustitución versionado' USING ERRCODE = '23514';
END;
$$;
CREATE TRIGGER documents_no_update BEFORE UPDATE ON cchia_admissions.application_documents
FOR EACH ROW EXECUTE FUNCTION cchia_admissions.prevent_evidence_update();
CREATE TRIGGER sponsors_no_update BEFORE UPDATE ON cchia_admissions.application_sponsors
FOR EACH ROW EXECUTE FUNCTION cchia_admissions.prevent_evidence_update();

-- La integridad de una solicitud jurídica se comprueba al confirmar la transacción.
CREATE FUNCTION cchia_admissions.check_complete_application() RETURNS trigger LANGUAGE plpgsql AS $$
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
CREATE CONSTRAINT TRIGGER complete_application AFTER INSERT OR UPDATE ON cchia_admissions.applications
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION cchia_admissions.check_complete_application();
CREATE CONSTRAINT TRIGGER complete_application_documents AFTER INSERT OR UPDATE OR DELETE ON cchia_admissions.application_documents
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION cchia_admissions.check_complete_application();
CREATE CONSTRAINT TRIGGER complete_application_sponsors AFTER INSERT OR UPDATE OR DELETE ON cchia_admissions.application_sponsors
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION cchia_admissions.check_complete_application();

CREATE FUNCTION cchia_admissions.prevent_event_change() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'El historial de postulaciones solo admite nuevos eventos' USING ERRCODE = '23514';
END;
$$;
CREATE TRIGGER events_append_only BEFORE UPDATE OR DELETE ON cchia_admissions.application_events
FOR EACH ROW EXECUTE FUNCTION cchia_admissions.prevent_event_change();
