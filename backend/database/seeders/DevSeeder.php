<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Datos SOLO para desarrollo local: dos socios activos (para poder patrocinar) y un usuario del Directorio.
 * Uso: php artisan db:seed --class=DevSeeder   (se niega a ejecutarse fuera de APP_ENV=local).
 * Las contraseñas se leen de DEV_SEED_PASSWORD en .env; sin ella no se crea nada.
 */
class DevSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DevSeeder solo se ejecuta con APP_ENV=local.');
        }
        $password = env('DEV_SEED_PASSWORD');
        if (! is_string($password) || strlen($password) < 12) {
            throw new RuntimeException('Define DEV_SEED_PASSWORD (mínimo 12 caracteres) en .env antes de ejecutar este seeder.');
        }
        $scope = DB::table('cchia_m02.access_scopes')->where('kind', 'global')->value('id');

        foreach ([
            ['socio1@example.org', 'Socio de Ejemplo Uno', 'socio'],
            ['socio2@example.org', 'Socio de Ejemplo Dos', 'socio'],
            ['directorio@example.org', 'Directorio de Ejemplo', 'directorio'],
        ] as [$email, $name, $role]) {
            $user = User::query()->firstOrCreate(['email' => $email], ['name' => $name, 'password' => $password]);
            $exists = DB::table('cchia_m02.role_assignments')->where('user_id', $user->id)->where('role_code', $role)->exists();
            if (! $exists) {
                DB::table('cchia_m02.role_assignments')->insert([
                    'id' => (string) Str::uuid(), 'user_id' => $user->id, 'role_code' => $role, 'scope_id' => $scope,
                    'status' => 'active', 'starts_at' => now()->subDay(), 'accepted_at' => now()->subDay(),
                ]);
            }
        }
    }
}
