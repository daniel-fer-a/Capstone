-- CCHIA - Diagnostico estructural v0.1 - 21/10/2026
-- Elaboracion: Codex. Revision del equipo pendiente.
-- Ejecutar completo en una ventana nueva, sin transacciones abiertas.
-- Consulta metadatos; no lee registros de personas ni modifica el esquema.
-- Copiar la celda "diagnostico" a un archivo JSON para compartirla por el chat.
-- Referencias: https://www.postgresql.org/docs/18/catalog-pg-constraint.html
-- https://www.postgresql.org/docs/18/sql-set-transaction.html

BEGIN TRANSACTION READ ONLY;
SET LOCAL statement_timeout = '15000ms';
SET LOCAL search_path = pg_catalog, public;

WITH tables_scope AS (
    SELECT c.oid, n.nspname AS schema_name, c.relname AS table_name
    FROM pg_class c
    JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE n.nspname IN ('public', 'cchia_m02', 'cchia_admissions')
      AND c.relkind IN ('r', 'p')
), columns_scope AS (
    SELECT t.schema_name, t.table_name, a.attnum AS ordinal_position,
           a.attname AS column_name, format_type(a.atttypid, a.atttypmod) AS sql_type,
           NOT a.attnotnull AS nullable,
           pg_get_expr(d.adbin, d.adrelid) AS default_expression,
           EXISTS (
               SELECT 1 FROM pg_constraint k
               WHERE k.conrelid = t.oid AND k.contype = 'p'
                 AND a.attnum = ANY(k.conkey)
           ) AS primary_key,
           EXISTS (
               SELECT 1 FROM pg_constraint k
               WHERE k.conrelid = t.oid AND k.contype IN ('p', 'u')
                 AND cardinality(k.conkey) = 1 AND a.attnum = ANY(k.conkey)
           ) AS unique_single_column
    FROM tables_scope t
    JOIN pg_attribute a ON a.attrelid = t.oid
    LEFT JOIN pg_attrdef d ON d.adrelid = t.oid AND d.adnum = a.attnum
    WHERE a.attnum > 0 AND NOT a.attisdropped
), constraints_scope AS (
    SELECT t.schema_name, t.table_name, k.conname AS constraint_name,
           k.contype::text AS constraint_type,
           pg_get_constraintdef(k.oid, true) AS definition,
           k.convalidated AS validated, k.condeferrable AS deferrable,
           k.condeferred AS initially_deferred
    FROM tables_scope t JOIN pg_constraint k ON k.conrelid = t.oid
), foreign_keys_scope AS (
    SELECT t.schema_name AS source_schema, t.table_name AS source_table,
           k.conname AS constraint_name,
           ARRAY(
               SELECT a.attname::text FROM unnest(k.conkey) WITH ORDINALITY x(attnum, pos)
               JOIN pg_attribute a ON a.attrelid = k.conrelid AND a.attnum = x.attnum
               ORDER BY x.pos
           ) AS source_columns,
           rn.nspname AS target_schema, rc.relname AS target_table,
           ARRAY(
               SELECT a.attname::text FROM unnest(k.confkey) WITH ORDINALITY x(attnum, pos)
               JOIN pg_attribute a ON a.attrelid = k.confrelid AND a.attnum = x.attnum
               ORDER BY x.pos
           ) AS target_columns,
           pg_get_constraintdef(k.oid, true) AS definition,
           k.convalidated AS validated
    FROM tables_scope t
    JOIN pg_constraint k ON k.conrelid = t.oid AND k.contype = 'f'
    JOIN pg_class rc ON rc.oid = k.confrelid
    JOIN pg_namespace rn ON rn.oid = rc.relnamespace
), indexes_scope AS (
    SELECT t.schema_name, t.table_name, ic.relname AS index_name,
           pg_get_indexdef(i.indexrelid) AS definition,
           i.indisunique AS is_unique, i.indisvalid AS valid, i.indisready AS ready
    FROM tables_scope t
    JOIN pg_index i ON i.indrelid = t.oid
    JOIN pg_class ic ON ic.oid = i.indexrelid
), enums_scope AS (
    SELECT n.nspname AS schema_name, ty.typname AS type_name,
           array_agg(e.enumlabel ORDER BY e.enumsortorder) AS values
    FROM pg_type ty JOIN pg_namespace n ON n.oid = ty.typnamespace
    JOIN pg_enum e ON e.enumtypid = ty.oid
    WHERE n.nspname IN ('public', 'cchia_m02', 'cchia_admissions')
    GROUP BY n.nspname, ty.typname
), triggers_scope AS (
    SELECT t.schema_name, t.table_name, tr.tgname AS trigger_name,
           tr.tgenabled::text AS enabled,
           pg_get_triggerdef(tr.oid, true) AS definition
    FROM tables_scope t JOIN pg_trigger tr ON tr.tgrelid = t.oid
    WHERE NOT tr.tgisinternal
)
SELECT jsonb_build_object(
    'metadata', jsonb_build_object(
        'format_version', '0.1',
        'captured_at', CURRENT_TIMESTAMP,
        'database', current_database(),
        'server_version', current_setting('server_version'),
        'server_version_num', current_setting('server_version_num')::integer,
        'version_string', version(),
        'read_only', current_setting('transaction_read_only') = 'on',
        'schemas_included', ARRAY['public', 'cchia_m02', 'cchia_admissions']
    ),
    'tables', COALESCE((SELECT jsonb_agg(to_jsonb(x) ORDER BY x.schema_name, x.table_name)
        FROM (SELECT schema_name, table_name FROM tables_scope) x), '[]'::jsonb),
    'columns', COALESCE((SELECT jsonb_agg(to_jsonb(x) ORDER BY x.schema_name, x.table_name, x.ordinal_position)
        FROM columns_scope x), '[]'::jsonb),
    'constraints', COALESCE((SELECT jsonb_agg(to_jsonb(x) ORDER BY x.schema_name, x.table_name, x.constraint_name)
        FROM constraints_scope x), '[]'::jsonb),
    'foreign_keys', COALESCE((SELECT jsonb_agg(to_jsonb(x) ORDER BY x.source_schema, x.source_table, x.constraint_name)
        FROM foreign_keys_scope x), '[]'::jsonb),
    'indexes', COALESCE((SELECT jsonb_agg(to_jsonb(x) ORDER BY x.schema_name, x.table_name, x.index_name)
        FROM indexes_scope x), '[]'::jsonb),
    'enums', COALESCE((SELECT jsonb_agg(to_jsonb(x) ORDER BY x.schema_name, x.type_name)
        FROM enums_scope x), '[]'::jsonb),
    'triggers', COALESCE((SELECT jsonb_agg(to_jsonb(x) ORDER BY x.schema_name, x.table_name, x.trigger_name)
        FROM triggers_scope x), '[]'::jsonb)
) AS diagnostico;

COMMIT;
