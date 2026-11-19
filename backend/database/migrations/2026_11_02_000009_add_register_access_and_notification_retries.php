<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE cchia_admissions.notification_retries (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    notification_id uuid NOT NULL REFERENCES cchia_admissions.notification_outbox(id),
    operation_hash varchar(64) NOT NULL UNIQUE CHECK (operation_hash ~ '^[a-f0-9]{64}$'),
    reason text NOT NULL CHECK (length(btrim(reason)) BETWEEN 10 AND 500),
    previous_attempts smallint NOT NULL CHECK (previous_attempts BETWEEN 1 AND 5),
    previous_error_type varchar(255) NOT NULL,
    requested_by_user_id varchar(36) NOT NULL REFERENCES public.users(id),
    requested_at timestamptz NOT NULL
);
CREATE INDEX notification_retry_history ON cchia_admissions.notification_retries(notification_id, requested_at);
CREATE TRIGGER notification_retries_immutable BEFORE UPDATE OR DELETE ON cchia_admissions.notification_retries
FOR EACH ROW EXECUTE FUNCTION cchia_admissions.prevent_evidence_update();
SQL);
        foreach ([
            'admissions.membership.read' => 'Consultar el registro privado de ingresos formalizados.',
            'admissions.membership.export' => 'Exportar el registro privado de ingresos con trazabilidad.',
            'admissions.notifications.manage' => 'Consultar avisos y reactivar expresamente los fallos agotados, con auditoría.',
        ] as $code => $description) DB::table('cchia_m02.permissions')->insert(['code' => $code, 'description' => $description]);
    }

    public function down(): void
    {
        throw new RuntimeException('El historial de reintentos se conserva. Preparar una reversión revisada.');
    }
};
