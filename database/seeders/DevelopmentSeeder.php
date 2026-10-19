<?php

namespace Database\Seeders;

use App\Core\Enum\Role as RoleEnum;
use App\Models\Field;
use App\Models\League;
use App\Models\LeagueReferee;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Database\Seeder;

class DevelopmentSeeder extends Seeder
{
    private const REFEREES = 10;

    private const REFEREES_PER_LEAGUE = 3;

    private const MIN_FIELDS_PER_LEAGUE = 1;

    private const MAX_FIELDS_PER_LEAGUE = 3;

    private const MANAGERS = 15;

    private const PLAYERS = 100;

    /**
     * Datos de prueba para trabajar en local. Nunca se ejecuta en producción
     * (ver DatabaseSeeder). Seguro de correr varias veces: el admin usa updateOrCreate
     * y el resto solo se crea si todavía no existe (ligas, usuarios por rol).
     */
    public function run(): void
    {
        $this->seedSuperAdmin();

        $leagues = $this->seedLeagues();
        $this->seedTournaments($leagues);
        $this->seedReferees($leagues);

        $this->seedUsersWithRole(RoleEnum::MANAGER, self::MANAGERS, 'DT');
        $this->seedUsersWithRole(RoleEnum::PLAYER, self::PLAYERS, 'JUG');
    }

    private function seedSuperAdmin(): void
    {
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@example.com'],
            ['first_name' => 'Admin', 'last_name' => 'Demo', 'user_code' => 'ADMIN001', 'password' => 'password', 'photo_url' => 'https://placehold.co/400x400?text=AD']
        );
        $admin->forceFill(['email_verified_at' => now()])->save();
        $admin->roles()->sync([RoleEnum::SUPERADMIN->value]);
    }

    /**
     * 2 encargados: el primero con 2 ligas y el segundo con 3.
     *
     * @return list<League>
     */
    private function seedLeagues(): array
    {
        if (League::query()->exists()) {
            return League::query()->orderBy('id')->get()->all();
        }

        $admins = User::factory(2)
            ->sequence(
                ['user_code' => 'LIGA001'],
                ['user_code' => 'LIGA002'],
            )
            ->create();

        foreach ($admins as $admin) {
            $admin->roles()->sync([RoleEnum::LEAGUE_ADMIN->value]);
        }

        $leagues = [];

        foreach ([0, 0, 1, 1, 1] as $adminIndex) {
            $leagues[] = League::factory()->create(['admin_user_id' => $admins[$adminIndex]->id]);
        }

        return $leagues;
    }

    /**
     * @param  list<League>  $leagues
     */
    private function seedTournaments(array $leagues): void
    {
        $startDate = now()->addMonth()->toDateString();

        foreach ($leagues as $league) {
            if ($league->tournaments()->exists()) {
                continue;
            }

            foreach (['Torneo Apertura', 'Torneo Clausura'] as $name) {
                Tournament::factory()->create([
                    'league_id' => $league->id,
                    'name' => $name,
                    'start_date' => $startDate,
                ]);
            }

            // Las canchas son de la liga, no del torneo: entre 1 y 3 por liga.
            Field::factory(random_int(self::MIN_FIELDS_PER_LEAGUE, self::MAX_FIELDS_PER_LEAGUE))
                ->create(['league_id' => $league->id]);
        }
    }

    /**
     * 10 árbitros repartidos en las ligas, 3 por liga (se reutilizan en círculo).
     *
     * @param  list<League>  $leagues
     */
    private function seedReferees(array $leagues): void
    {
        if (LeagueReferee::query()->exists()) {
            return;
        }

        $referees = User::factory(self::REFEREES)
            ->sequence(fn ($sequence) => ['user_code' => sprintf('ARB%03d', $sequence->index + 1)])
            ->create();

        foreach ($referees as $referee) {
            $referee->roles()->sync([RoleEnum::REFEREE->value]);
        }

        foreach ($leagues as $leagueIndex => $league) {
            for ($i = 0; $i < self::REFEREES_PER_LEAGUE; $i++) {
                $referee = $referees[($leagueIndex * self::REFEREES_PER_LEAGUE + $i) % self::REFEREES];

                LeagueReferee::factory()->create([
                    'league_id' => $league->id,
                    'user_id' => $referee->id,
                ]);
            }
        }
    }

    private function seedUsersWithRole(RoleEnum $role, int $total, string $codePrefix): void
    {
        $existing = User::query()->whereHas('roles', fn ($q) => $q->where('roles.id', $role->value))->count();
        $missing = $total - $existing;

        if ($missing <= 0) {
            return;
        }

        User::factory($missing)
            ->sequence(fn ($sequence) => ['user_code' => sprintf('%s%03d', $codePrefix, $existing + $sequence->index + 1)])
            ->create()
            ->each(fn (User $user) => $user->roles()->sync([$role->value]));
    }
}
