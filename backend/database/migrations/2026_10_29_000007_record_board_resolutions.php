<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE cchia_admissions.board_resolutions (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    application_id uuid NOT NULL UNIQUE REFERENCES cchia_admissions.applications(id),
    outcome varchar(20) NOT NULL CHECK (outcome IN ('approved', 'rejected')),
    reason text NOT NULL CHECK (btrim(reason) <> '' AND length(reason) <= 10000),
    meeting_date date NOT NULL,
    minutes_reference varchar(255) NOT NULL CHECK (btrim(minutes_reference) <> ''),
    recorded_by_user_id varchar(36) NOT NULL REFERENCES public.users(id),
    recorded_at timestamptz NOT NULL
);
CREATE TRIGGER board_resolutions_immutable BEFORE UPDATE OR DELETE ON cchia_admissions.board_resolutions
FOR EACH ROW EXECUTE FUNCTION cchia_admissions.prevent_evidence_update();

CREATE FUNCTION cchia_admissions.check_board_resolution() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE target uuid; a cchia_admissions.applications%ROWTYPE;
BEGIN
    IF TG_TABLE_NAME = 'applications' THEN target := NEW.id; ELSE target := NEW.application_id; END IF;
    SELECT * INTO a FROM cchia_admissions.applications WHERE id = target;
    IF NOT FOUND OR a.rules_version <> '2026-10-29-staged-3' THEN RETURN NULL; END IF;
    IF a.status IN ('approved', 'rejected') THEN
        IF NOT EXISTS (SELECT 1 FROM cchia_admissions.board_resolutions r WHERE r.application_id = target
            AND r.outcome = a.status AND r.reason = a.decision_reason
            AND r.recorded_by_user_id = a.decision_by_user_id AND r.recorded_at = a.decision_at) THEN
            RAISE EXCEPTION 'La resolución requiere su acuerdo y responsable registrados' USING ERRCODE = '23514';
        END IF;
    ELSIF EXISTS (SELECT 1 FROM cchia_admissions.board_resolutions WHERE application_id = target) THEN
        RAISE EXCEPTION 'El estado debe corresponder al acuerdo registrado' USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;
CREATE CONSTRAINT TRIGGER recorded_board_application AFTER INSERT OR UPDATE ON cchia_admissions.applications
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION cchia_admissions.check_board_resolution();
CREATE CONSTRAINT TRIGGER recorded_board_agreement AFTER INSERT ON cchia_admissions.board_resolutions
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION cchia_admissions.check_board_resolution();
SQL);
        DB::table('cchia_m02.permissions')->insert([
            'code' => 'admissions.decisions.record', 'description' => 'Registrar una resolución adoptada por el Directorio con referencia de acta.',
        ]);
    }

    public function down(): void
    {
        throw new RuntimeException('Las resoluciones registradas se conservan. Preparar una reversión revisada.');
    }
};
