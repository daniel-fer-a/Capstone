<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::scalar("SELECT to_regnamespace('cchia_m02')") !== null) {
            throw new RuntimeException('cchia_m02 ya existe. Se requiere revisar la integración, no reinstalar el Modelo 02.');
        }
        $sql = file_get_contents(database_path('reference/modelo-02-catalogo-roles-alineado-v1.0.sql'));
        // Mantener intacto el archivo fuente. Laravel ya aporta la transacción.
        $sql = preg_replace('/^BEGIN;\s*$/m', '', $sql);
        $sql = preg_replace('/^COMMIT;\s*$/m', '', $sql);
        $sql = str_replace('SET search_path TO cchia_m02, public;', 'SET LOCAL search_path TO cchia_m02, public;', $sql);
        DB::unprepared($sql);
        DB::table('cchia_m02.access_scopes')->insert([
            'id' => (string) Illuminate\Support\Str::uuid(),
            'kind' => 'global', 'label' => 'Ámbito global CCHIA',
        ]);
    }

    public function down(): void
    {
        // Este esquema lo crea exclusivamente esta instalación aislada.
        DB::unprepared('DROP SCHEMA cchia_m02 CASCADE');
    }
};
