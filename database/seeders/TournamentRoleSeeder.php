<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Siembra los roles del sistema de torneos en la tabla `roles` de la base del API.
 * Los ids son fijos porque el middleware `permission:<ids>` los usa directamente.
 */
class TournamentRoleSeeder extends Seeder
{
    public const SUPERADMIN = 1;

    public const LEAGUE_ADMIN = 2;

    public const REFEREE = 3;

    public const MANAGER = 4;

    public const PLAYER = 5;

    public function run(): void
    {
        $column = Schema::hasColumn('roles', 'nombre') ? 'nombre' : 'name';

        $roles = [
            self::SUPERADMIN => 'superadmin',
            self::LEAGUE_ADMIN => 'league_admin',
            self::REFEREE => 'referee',
            self::MANAGER => 'manager',
            self::PLAYER => 'player',
        ];

        foreach ($roles as $id => $name) {
            DB::table('roles')->updateOrInsert(['id' => $id], [$column => $name]);
        }
    }
}
