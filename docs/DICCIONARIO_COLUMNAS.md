# Diccionario de columnas de postulaciones

Generado desde information_schema después de ejecutar las migraciones en PostgreSQL 18 (motor WASM de pruebas). Las relaciones y reglas están descritas en MODELO_POSTULACIONES.md.

## cchia_admissions.application_documents

| Columna | Tipo SQL | Acepta NULL | Valor predeterminado |
|---|---|---|---|
| id | uuid | No | gen_random_uuid() |
| application_id | uuid | No | — |
| kind | character varying(30) | No | — |
| disk | character varying(50) | No | — |
| storage_key | character varying(512) | No | — |
| original_name | character varying(255) | No | — |
| mime_type | character varying(100) | No | — |
| bytes | integer | No | — |
| sha256 | character varying(64) | No | — |
| uploaded_at | timestamp with time zone | No | now() |

## cchia_admissions.application_events

| Columna | Tipo SQL | Acepta NULL | Valor predeterminado |
|---|---|---|---|
| id | uuid | No | gen_random_uuid() |
| application_id | uuid | No | — |
| actor_user_id | character varying(36) | Sí | — |
| actor_kind | character varying(30) | No | — |
| action | character varying(80) | No | — |
| outcome | character varying(30) | No | — |
| metadata | jsonb | No | '{}'::jsonb |
| occurred_at | timestamp with time zone | No | now() |

## cchia_admissions.application_sponsors

| Columna | Tipo SQL | Acepta NULL | Valor predeterminado |
|---|---|---|---|
| id | uuid | No | gen_random_uuid() |
| application_id | uuid | No | — |
| sponsor_user_id | character varying(36) | No | — |
| role_assignment_id | uuid | No | — |
| position | smallint | No | — |
| checked_at | timestamp with time zone | No | now() |

## cchia_admissions.applications

| Columna | Tipo SQL | Acepta NULL | Valor predeterminado |
|---|---|---|---|
| id | uuid | No | gen_random_uuid() |
| reference | character varying(40) | No | — |
| tracking_token_hash | character varying(64) | No | — |
| membership | character varying(20) | No | — |
| legal | character varying(20) | No | — |
| natural_name | character varying(255) | Sí | — |
| natural_identifier | character varying(80) | Sí | — |
| organization_name | character varying(255) | Sí | — |
| organization_identifier | character varying(80) | Sí | — |
| representative_name | character varying(255) | Sí | — |
| representative_identifier | character varying(80) | Sí | — |
| email | character varying(255) | No | — |
| address | character varying(500) | No | — |
| ai_activity | text | No | — |
| status | character varying(20) | No | 'received'::character varying |
| rules_version | character varying(60) | No | — |
| declaration_accepted_at | timestamp with time zone | No | — |
| submitted_at | timestamp with time zone | No | now() |
| decision_at | timestamp with time zone | Sí | — |
| decision_by_user_id | character varying(36) | Sí | — |
| decision_reason | text | Sí | — |

