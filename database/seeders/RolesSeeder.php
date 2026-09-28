<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesSeeder extends Seeder
{
    /**
     * Catálogo real de roles de la app. Idempotente: puede correrse en cualquier
     * ambiente (incluido producción) sin duplicar registros ni fallar por unique.
     */
    public function run(): void
    {
        $roles = [
            'Super Administrador',
            'Administrador Liga',
            'Árbitro',
            'Director Técnico',
            'Jugador',
        ];

        foreach ($roles as $name) {
            Role::query()->updateOrCreate(['name' => $name]);
        }
    }
}
