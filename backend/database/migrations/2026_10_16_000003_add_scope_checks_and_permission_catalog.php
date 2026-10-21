<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE FUNCTION cchia_m02.check_role_scope_kind() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM cchia_m02.roles r
        JOIN cchia_m02.access_scopes s ON s.id = NEW.scope_id
        WHERE r.code = NEW.role_code AND r.default_scope = s.kind
    ) THEN
        RAISE EXCEPTION 'El ámbito no corresponde al rol' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER check_assignment_scope BEFORE INSERT OR UPDATE OF role_code, scope_id
ON cchia_m02.role_assignments FOR EACH ROW EXECUTE FUNCTION cchia_m02.check_role_scope_kind();
CREATE TRIGGER check_invitation_scope BEFORE INSERT OR UPDATE OF role_code, scope_id
ON cchia_m02.role_invitations FOR EACH ROW EXECUTE FUNCTION cchia_m02.check_role_scope_kind();
SQL);
        DB::table('cchia_m02.permissions')->insert([
            ['code' => 'admissions.read', 'description' => 'Propuesta: consultar postulaciones con política institucional explícita.'],
            ['code' => 'admissions.documents.read', 'description' => 'Propuesta: descargar antecedentes privados con política institucional explícita.'],
        ]);
        // Ningún rol obtiene estos permisos ni se crea una asignación de usuario.
    }

    public function down(): void
    {
        DB::table('cchia_m02.permissions')->whereIn('code', ['admissions.read', 'admissions.documents.read'])->delete();
        DB::unprepared('DROP TRIGGER check_invitation_scope ON cchia_m02.role_invitations; DROP TRIGGER check_assignment_scope ON cchia_m02.role_assignments; DROP FUNCTION cchia_m02.check_role_scope_kind()');
    }
};
