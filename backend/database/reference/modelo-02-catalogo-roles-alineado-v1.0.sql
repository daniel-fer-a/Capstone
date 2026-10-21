-- FICHA DEL DOCUMENTO
-- Autor: Sebastián Araya
-- Fecha: 15/08/2026
-- Versión: 1.0
-- Estado: Emitido
-- Antecedente: Modelo sugerido de datos para cubrir el desarrollo de forma alineada y con gobernanza de mayor nivel de detalle
-- (Existe una nota de recomendación - Esta es la forma más segura de implementación)
-- En el documento complementario: guia-modelos-roles-cchia-v1.0.pdf
-- Derechos preexistentes: No aplican
-- Canal de entrega: Drive de la vertical
-- Licencia: Documento de trabajo interno CCHIA
--
-- CCHIA | Modelo 02: catálogo de roles v1.0 alineado + interlocutor de convenio
-- PostgreSQL 14+. Evoluciona el esquema heredado public.users, public.companies,
-- public.commissions y public.resources. NO instalar junto con modelo-01.
-- IDs heredados se preservan como varchar(36); nuevas entidades usan UUID.

BEGIN;

CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE SCHEMA IF NOT EXISTS cchia_m02;
SET search_path TO cchia_m02, public;

CREATE TYPE role_layer AS ENUM ('institutional', 'operational', 'temporary', 'control', 'external');
CREATE TYPE scope_kind AS ENUM ('global', 'organization', 'commission', 'mesa', 'agreement', 'resource', 'electoral_process');
CREATE TYPE assignment_status AS ENUM ('pending', 'active', 'revoked', 'expired');
CREATE TYPE agreement_status AS ENUM ('draft', 'active', 'expired', 'terminated');
CREATE TYPE agreement_party_type AS ENUM ('cchia', 'counterparty');
CREATE TYPE invitation_status AS ENUM ('pending', 'accepted', 'declined', 'cancelled', 'expired');

CREATE TABLE IF NOT EXISTS roles (
  code varchar(80) PRIMARY KEY,
  layer role_layer NOT NULL,
  name varchar(160) NOT NULL UNIQUE,
  default_scope scope_kind NOT NULL,
  institutional_authority boolean NOT NULL DEFAULT false,
  voting_eligible boolean NOT NULL DEFAULT false,
  description text NOT NULL,
  is_active boolean NOT NULL DEFAULT true
);

CREATE TABLE IF NOT EXISTS permissions (
  code varchar(120) PRIMARY KEY,
  description text NOT NULL
);

CREATE TABLE IF NOT EXISTS role_permissions (
  role_code varchar(80) NOT NULL REFERENCES roles(code) ON DELETE CASCADE,
  permission_code varchar(120) NOT NULL REFERENCES permissions(code) ON DELETE CASCADE,
  PRIMARY KEY (role_code, permission_code)
);

-- Un scope es una referencia tipada y con FK, no una pareja polimórfica libre.
CREATE TABLE IF NOT EXISTS access_scopes (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  kind scope_kind NOT NULL,
  company_id varchar(36) REFERENCES public.companies(id) ON DELETE CASCADE,
  commission_id varchar(36) REFERENCES public.commissions(id) ON DELETE CASCADE,
  mesa_id uuid,
  agreement_id uuid,
  resource_id varchar(36) REFERENCES public.resources(id) ON DELETE CASCADE,
  electoral_process_id uuid,
  label varchar(255) NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE NULLS NOT DISTINCT (kind, company_id, commission_id, mesa_id, agreement_id, resource_id, electoral_process_id),
  CHECK (
    (kind = 'global' AND company_id IS NULL AND commission_id IS NULL AND mesa_id IS NULL AND agreement_id IS NULL AND resource_id IS NULL AND electoral_process_id IS NULL) OR
    (kind = 'organization' AND company_id IS NOT NULL AND commission_id IS NULL AND mesa_id IS NULL AND agreement_id IS NULL AND resource_id IS NULL AND electoral_process_id IS NULL) OR
    (kind = 'commission' AND company_id IS NULL AND commission_id IS NOT NULL AND mesa_id IS NULL AND agreement_id IS NULL AND resource_id IS NULL AND electoral_process_id IS NULL) OR
    (kind = 'mesa' AND company_id IS NULL AND commission_id IS NULL AND mesa_id IS NOT NULL AND agreement_id IS NULL AND resource_id IS NULL AND electoral_process_id IS NULL) OR
    (kind = 'agreement' AND company_id IS NULL AND commission_id IS NULL AND mesa_id IS NULL AND agreement_id IS NOT NULL AND resource_id IS NULL AND electoral_process_id IS NULL) OR
    (kind = 'resource' AND company_id IS NULL AND commission_id IS NULL AND mesa_id IS NULL AND agreement_id IS NULL AND resource_id IS NOT NULL AND electoral_process_id IS NULL) OR
    (kind = 'electoral_process' AND company_id IS NULL AND commission_id IS NULL AND mesa_id IS NULL AND agreement_id IS NULL AND resource_id IS NULL AND electoral_process_id IS NOT NULL)
  )
);

CREATE TABLE IF NOT EXISTS mesas (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  commission_id varchar(36) NOT NULL REFERENCES public.commissions(id) ON DELETE CASCADE,
  name varchar(255) NOT NULL,
  description text,
  status varchar(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'closed')),
  created_by_user_id varchar(36) NOT NULL REFERENCES public.users(id),
  created_at timestamptz NOT NULL DEFAULT now(),
  closed_at timestamptz,
  UNIQUE (commission_id, name)
);
ALTER TABLE access_scopes
  ADD CONSTRAINT fk_scope_mesa FOREIGN KEY (mesa_id) REFERENCES mesas(id) ON DELETE CASCADE;

CREATE TABLE IF NOT EXISTS agreements (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  code varchar(80) NOT NULL UNIQUE,
  title varchar(255) NOT NULL,
  status agreement_status NOT NULL DEFAULT 'draft',
  effective_from date,
  effective_until date,
  created_by_user_id varchar(36) NOT NULL REFERENCES public.users(id),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CHECK (effective_until IS NULL OR effective_from IS NULL OR effective_until >= effective_from)
);
ALTER TABLE access_scopes
  ADD CONSTRAINT fk_scope_agreement FOREIGN KEY (agreement_id) REFERENCES agreements(id) ON DELETE CASCADE;

CREATE TABLE IF NOT EXISTS agreement_parties (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  agreement_id uuid NOT NULL REFERENCES agreements(id) ON DELETE CASCADE,
  company_id varchar(36) NOT NULL REFERENCES public.companies(id),
  party_type agreement_party_type NOT NULL,
  UNIQUE (agreement_id, company_id),
  UNIQUE NULLS NOT DISTINCT (agreement_id, party_type)
);

CREATE TABLE IF NOT EXISTS agreement_artifacts (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  agreement_id uuid NOT NULL REFERENCES agreements(id) ON DELETE CASCADE,
  title varchar(255) NOT NULL,
  storage_key varchar(1024) NOT NULL UNIQUE,
  mime_type varchar(150),
  version_no integer NOT NULL DEFAULT 1 CHECK (version_no > 0),
  created_by_user_id varchar(36) NOT NULL REFERENCES public.users(id),
  created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS electoral_processes (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name varchar(255) NOT NULL,
  starts_at timestamptz NOT NULL,
  ends_at timestamptz NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'planned' CHECK (status IN ('planned', 'active', 'closed')),
  created_at timestamptz NOT NULL DEFAULT now(),
  CHECK (ends_at > starts_at)
);
ALTER TABLE access_scopes
  ADD CONSTRAINT fk_scope_electoral_process FOREIGN KEY (electoral_process_id) REFERENCES electoral_processes(id) ON DELETE CASCADE;

-- Asignación multirrol, acotada por ámbito, estado y vigencia.
CREATE TABLE IF NOT EXISTS role_assignments (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id varchar(36) NOT NULL REFERENCES public.users(id) ON DELETE CASCADE,
  role_code varchar(80) NOT NULL REFERENCES roles(code),
  scope_id uuid NOT NULL REFERENCES access_scopes(id) ON DELETE CASCADE,
  status assignment_status NOT NULL DEFAULT 'pending',
  assigned_by_user_id varchar(36) REFERENCES public.users(id),
  assigned_at timestamptz NOT NULL DEFAULT now(),
  accepted_at timestamptz,
  starts_at timestamptz NOT NULL DEFAULT now(),
  ends_at timestamptz,
  revoked_at timestamptz,
  revocation_reason text,
  source varchar(60) NOT NULL DEFAULT 'manual',
  CHECK (ends_at IS NULL OR ends_at > starts_at)
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_role_assignment_active
  ON role_assignments(user_id, role_code, scope_id)
  WHERE status IN ('pending', 'active');

-- Vínculo verificable entre usuario externo, contraparte y convenio.
-- El permiso efectivo deriva de role_assignments: interlocutor_convenio + scope del convenio.
CREATE TABLE IF NOT EXISTS agreement_interlocutors (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  agreement_id uuid NOT NULL REFERENCES agreements(id) ON DELETE CASCADE,
  counterparty_company_id varchar(36) NOT NULL REFERENCES public.companies(id),
  user_id varchar(36) NOT NULL REFERENCES public.users(id) ON DELETE CASCADE,
  designation varchar(40) NOT NULL CHECK (designation IN ('interlocutor', 'reviewer')),
  appointment_reference varchar(255),
  starts_at timestamptz NOT NULL DEFAULT now(),
  ends_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  CHECK (ends_at IS NULL OR ends_at > starts_at),
  UNIQUE (agreement_id, user_id, designation),
  FOREIGN KEY (agreement_id, counterparty_company_id)
    REFERENCES agreement_parties(agreement_id, company_id)
);

CREATE TABLE IF NOT EXISTS role_invitations (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  invitee_email varchar(255) NOT NULL,
  invitee_user_id varchar(36) REFERENCES public.users(id),
  role_code varchar(80) NOT NULL REFERENCES roles(code),
  scope_id uuid NOT NULL REFERENCES access_scopes(id) ON DELETE CASCADE,
  invited_by_user_id varchar(36) NOT NULL REFERENCES public.users(id),
  status invitation_status NOT NULL DEFAULT 'pending',
  token_hash varchar(255) NOT NULL UNIQUE,
  expires_at timestamptz NOT NULL,
  accepted_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS authorization_audit_log (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  actor_user_id varchar(36) REFERENCES public.users(id),
  subject_user_id varchar(36) REFERENCES public.users(id),
  assignment_id uuid REFERENCES role_assignments(id) ON DELETE SET NULL,
  action varchar(100) NOT NULL,
  entity_type varchar(80) NOT NULL,
  entity_id varchar(80) NOT NULL,
  metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
  occurred_at timestamptz NOT NULL DEFAULT now()
);

INSERT INTO roles(code, layer, name, default_scope, institutional_authority, voting_eligible, description) VALUES
  ('socio', 'institutional', 'Socio', 'global', true, true, 'Acceso, participación y voto cuando corresponda.'),
  ('representante_persona_juridica_socia', 'institutional', 'Representante de persona jurídica socia', 'organization', true, false, 'Representación vigente y única habilitada de una entidad socia.'),
  ('directorio', 'institutional', 'Directorio', 'global', true, false, 'Decisiones, convocatorias, membresía y comisiones según atribuciones.'),
  ('presidente_vicepresidente', 'institutional', 'Presidente / Vicepresidente', 'global', true, false, 'Presidencia y subrogación conforme a reglas vigentes.'),
  ('secretario', 'institutional', 'Secretario', 'global', true, false, 'Registro de socios, citaciones, actas, archivo y trazabilidad documental.'),
  ('tesorero', 'institutional', 'Tesorero', 'global', true, false, 'Flujos financieros, cuotas y reportes propios con segregación de funciones.'),
  ('comision_revisora_cuentas', 'institutional', 'Comisión Revisora de Cuentas', 'global', true, false, 'Consulta fiscalizadora de antecedentes financieros sin administración operativa.'),
  ('comite_electoral', 'temporary', 'Comité Electoral', 'electoral_process', true, false, 'Supervisión y certificación electoral durante su vigencia.'),
  ('administrador_plataforma', 'operational', 'Administrador de plataforma', 'global', false, false, 'Configuración y soporte auditados; no concede facultad institucional ni voto.'),
  ('lider_comision', 'operational', 'Líder de comisión', 'commission', false, false, 'Gestión de miembros, proyectos, eventos y contenidos de su comisión.'),
  ('miembro_comision', 'operational', 'Miembro de comisión', 'commission', false, false, 'Colaboración y acceso según permisos asignados.'),
  ('observador_invitado', 'operational', 'Observador / invitado', 'resource', false, false, 'Acceso limitado, usualmente lectura y con expiración.'),
  ('responsable_proteccion_datos', 'control', 'Responsable de protección de datos', 'global', true, false, 'Consentimientos, solicitudes de datos, políticas y controles.'),
  ('interlocutor_convenio', 'external', 'Interlocutor de entidad en convenio', 'agreement', false, false, 'Comunicación y revisión delimitadas por el convenio; sin voto ni facultad institucional.')
ON CONFLICT (code) DO UPDATE SET
  layer = EXCLUDED.layer, name = EXCLUDED.name, default_scope = EXCLUDED.default_scope,
  institutional_authority = EXCLUDED.institutional_authority, voting_eligible = EXCLUDED.voting_eligible,
  description = EXCLUDED.description;

INSERT INTO permissions(code, description) VALUES
  ('commission.members.invite_secretary', 'Invitar secretario/a institucional o colaborador autorizado en comisión.'),
  ('commission.members.invite_member', 'Invitar miembros dentro de la comisión.'),
  ('commission.manage', 'Gestionar proyectos, eventos y contenidos de la comisión.'),
  ('agreement.artifacts.read', 'Leer artefactos del convenio en su ámbito.'),
  ('agreement.artifacts.comment', 'Comentar artefactos del convenio en su ámbito.'),
  ('agreement.artifacts.review', 'Revisar artefactos del convenio según designación.'),
  ('governance.global.read', 'Consultar información institucional global autorizada.'),
  ('financial.audit.read', 'Consultar antecedentes financieros con finalidad fiscalizadora.'),
  ('personal_data.manage', 'Gestionar consentimientos y solicitudes de datos.'),
  ('platform.configure', 'Configurar y dar soporte técnico auditado.')
ON CONFLICT (code) DO UPDATE SET description = EXCLUDED.description;

INSERT INTO role_permissions(role_code, permission_code) VALUES
  ('lider_comision', 'commission.members.invite_secretary'),
  ('lider_comision', 'commission.members.invite_member'),
  ('lider_comision', 'commission.manage'),
  ('interlocutor_convenio', 'agreement.artifacts.read'),
  ('interlocutor_convenio', 'agreement.artifacts.comment'),
  ('interlocutor_convenio', 'agreement.artifacts.review'),
  ('directorio', 'governance.global.read'),
  ('comision_revisora_cuentas', 'financial.audit.read'),
  ('responsable_proteccion_datos', 'personal_data.manage'),
  ('administrador_plataforma', 'platform.configure')
ON CONFLICT DO NOTHING;

CREATE INDEX IF NOT EXISTS ix_m02_assignments_effective
  ON role_assignments(user_id, scope_id, role_code)
  WHERE status = 'active';
CREATE INDEX IF NOT EXISTS ix_m02_scopes_commission ON access_scopes(commission_id) WHERE commission_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS ix_m02_scopes_agreement ON access_scopes(agreement_id) WHERE agreement_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS ix_m02_interlocutors_user ON agreement_interlocutors(user_id, agreement_id);

-- Regla requerida en la capa de servicio o trigger: validar que role.default_scope = access_scope.kind.
-- Regla requerida para invitaciones: lider_comision solo puede invitar roles autorizados en su mismo scope de comisión.
-- Regla requerida para interlocutor: role_assignment activo debe compartir scope del convenio y no superar effective_until.
-- Se recomienda que la aplicación cree un scope global único, y un scope por entidad al crear compañías, comisiones, mesas, convenios, recursos y procesos electorales.

COMMIT;
