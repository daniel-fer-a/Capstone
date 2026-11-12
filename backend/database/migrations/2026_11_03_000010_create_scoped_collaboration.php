<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE SCHEMA cchia_workspace;
CREATE TABLE cchia_workspace.profiles (
    user_id varchar(36) PRIMARY KEY REFERENCES public.users(id),
    biography varchar(1000) NOT NULL DEFAULT '',
    share_biography boolean NOT NULL DEFAULT false,
    version integer NOT NULL CHECK (version > 0),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE cchia_workspace.commissions (
    commission_id varchar(36) PRIMARY KEY REFERENCES public.commissions(id),
    scope_id uuid NOT NULL UNIQUE REFERENCES cchia_m02.access_scopes(id),
    normalized_name varchar(160) NOT NULL UNIQUE,
    description varchar(3000) NOT NULL DEFAULT '',
    status varchar(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active','archived')),
    version integer NOT NULL DEFAULT 1 CHECK (version > 0),
    created_by_user_id varchar(36) NOT NULL REFERENCES public.users(id),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE cchia_workspace.projects (
    id uuid PRIMARY KEY,
    commission_id varchar(36) NOT NULL REFERENCES cchia_workspace.commissions(commission_id),
    name varchar(160) NOT NULL,
    description varchar(3000) NOT NULL DEFAULT '',
    status varchar(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active','archived')),
    version integer NOT NULL DEFAULT 1 CHECK (version > 0),
    created_by_user_id varchar(36) NOT NULL REFERENCES public.users(id),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (id,commission_id)
);
CREATE UNIQUE INDEX workspace_project_name ON cchia_workspace.projects(commission_id,lower(name));
CREATE TABLE cchia_workspace.tasks (
    id uuid PRIMARY KEY,
    commission_id varchar(36) NOT NULL REFERENCES cchia_workspace.commissions(commission_id),
    project_id uuid NOT NULL,
    title varchar(160) NOT NULL,
    description varchar(3000) NOT NULL DEFAULT '',
    status varchar(20) NOT NULL DEFAULT 'todo' CHECK (status IN ('todo','in_progress','done')),
    priority varchar(20) NOT NULL DEFAULT 'normal' CHECK (priority IN ('low','normal','high')),
    assignee_user_id varchar(36) REFERENCES public.users(id),
    due_date date,
    version integer NOT NULL DEFAULT 1 CHECK (version > 0),
    created_by_user_id varchar(36) NOT NULL REFERENCES public.users(id),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (project_id,commission_id) REFERENCES cchia_workspace.projects(id,commission_id)
);
CREATE INDEX workspace_tasks_project ON cchia_workspace.tasks(commission_id,project_id,status);
CREATE TABLE cchia_workspace.invitation_details (
    invitation_id uuid PRIMARY KEY REFERENCES cchia_m02.role_invitations(id),
    commission_id varchar(36) NOT NULL REFERENCES cchia_workspace.commissions(commission_id),
    assignment_ends_at timestamptz,
    appointment_reference varchar(255),
    assignment_id uuid REFERENCES cchia_m02.role_assignments(id)
);
CREATE UNIQUE INDEX workspace_open_invitation ON cchia_m02.role_invitations(lower(invitee_email),role_code,scope_id)
    WHERE status='pending' AND role_code IN ('lider_comision','miembro_comision');
CREATE TABLE cchia_workspace.mail_outbox (
    id uuid PRIMARY KEY,
    invitation_id uuid NOT NULL UNIQUE REFERENCES cchia_workspace.invitation_details(invitation_id),
    token_encrypted text,
    attempts smallint NOT NULL DEFAULT 0 CHECK (attempts BETWEEN 0 AND 5),
    available_at timestamptz NOT NULL DEFAULT now(),
    locked_at timestamptz,
    sent_at timestamptz,
    abandoned_at timestamptz,
    last_error_type varchar(255),
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX workspace_mail_pending ON cchia_workspace.mail_outbox(available_at) WHERE sent_at IS NULL AND abandoned_at IS NULL;
CREATE TABLE cchia_workspace.resources (
    id uuid PRIMARY KEY,
    commission_id varchar(36) NOT NULL REFERENCES cchia_workspace.commissions(commission_id),
    title varchar(160) NOT NULL,
    original_name varchar(255) NOT NULL,
    storage_key varchar(1024) NOT NULL UNIQUE,
    mime_type varchar(100) NOT NULL CHECK (mime_type IN ('application/pdf','image/jpeg','image/png')),
    byte_size integer NOT NULL CHECK (byte_size BETWEEN 1 AND 5242880),
    sha256 char(64) NOT NULL,
    uploaded_by_user_id varchar(36) NOT NULL REFERENCES public.users(id),
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE cchia_workspace.events (
    id uuid PRIMARY KEY,
    commission_id varchar(36) REFERENCES cchia_workspace.commissions(commission_id),
    actor_user_id varchar(36) REFERENCES public.users(id),
    action varchar(100) NOT NULL,
    entity_type varchar(50) NOT NULL,
    entity_id varchar(80) NOT NULL,
    metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
    occurred_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX workspace_events_commission ON cchia_workspace.events(commission_id,occurred_at);
CREATE TABLE cchia_workspace.operations (
    id uuid PRIMARY KEY,
    actor_user_id varchar(36) NOT NULL REFERENCES public.users(id),
    action varchar(80) NOT NULL,
    operation_hash char(64) NOT NULL UNIQUE,
    payload_hash char(64) NOT NULL,
    result jsonb NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE FUNCTION cchia_workspace.block_evidence_changes() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'La evidencia de colaboración es inmutable' USING ERRCODE='23514';
END;
$$;
CREATE TRIGGER workspace_events_immutable BEFORE UPDATE OR DELETE ON cchia_workspace.events FOR EACH ROW EXECUTE FUNCTION cchia_workspace.block_evidence_changes();
CREATE TRIGGER workspace_operations_immutable BEFORE UPDATE OR DELETE ON cchia_workspace.operations FOR EACH ROW EXECUTE FUNCTION cchia_workspace.block_evidence_changes();
CREATE TRIGGER workspace_resources_immutable BEFORE UPDATE OR DELETE ON cchia_workspace.resources FOR EACH ROW EXECUTE FUNCTION cchia_workspace.block_evidence_changes();
SQL);
        DB::table('cchia_m02.permissions')->insert(['code'=>'workspace.commissions.manage','description'=>'Crear y administrar comisiones y designaciones operativas con política explícita.']);
    }

    public function down(): void
    {
        throw new RuntimeException('Esta migración conserva cuentas, asignaciones y evidencia. La reversión requiere un plan explícito.');
    }
};
