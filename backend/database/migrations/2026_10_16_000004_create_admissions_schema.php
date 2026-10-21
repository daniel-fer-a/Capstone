<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::scalar("SELECT to_regnamespace('cchia_admissions')") !== null) {
            throw new RuntimeException('cchia_admissions ya existe. Revisar la integración antes de continuar.');
        }
        DB::unprepared(file_get_contents(database_path('sql/admissions.sql')));
    }

    public function down(): void
    {
        DB::unprepared('DROP SCHEMA cchia_admissions CASCADE');
    }
};
