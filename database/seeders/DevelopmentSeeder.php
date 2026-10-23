<?php

namespace Database\Seeders;

use App\Contracts\Services\MatchServiceInterface;
use App\Core\Enum\MatchStatus;
use App\Core\Enum\PlayerGender;
use App\Core\Enum\Role as RoleEnum;
use App\Core\Enum\RosterStatus;
use App\Core\Enum\TeamStatus;
use App\Core\Enum\TournamentGender;
use App\Core\Enum\TournamentGroupType;
use App\Core\Enum\TournamentStatus;
use App\Models\Field;
use App\Models\GameMatch;
use App\Models\League;
use App\Models\LeagueReferee;
use App\Models\Player;
use App\Models\PlayerLeagueBan;
use App\Models\PlayoffSource;
use App\Models\Registration;
use App\Models\Roster;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentGroup;
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

    /** Inscritos por torneo: los primeros de TEAM_NAMES; el resto queda libre para probar la inscripción. */
    private const TEAMS_PER_TOURNAMENT = 10;

    /** Equipos por liga. */
    private const TEAM_NAMES = [
        'Halcones', 'Tigres', 'Águilas', 'Leones', 'Toros',
        'Lobos', 'Pumas', 'Panteras', 'Rayos', 'Cometas',
        'Dragones', 'Cóndores', 'Jaguares', 'Titanes',
    ];

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
        $this->seedTeams($leagues);
        $this->seedGroupsTournament($leagues);
        $this->seedPlayoffStageTournaments($leagues);
        $this->seedFourGroupsTournament($leagues);
        $this->seedOddTournament($leagues);
        $this->seedMinimumsTournament($leagues);
        $this->seedUsersWithRole(RoleEnum::PLAYER, self::PLAYERS, 'JUG');
        $this->seedPlayers();
        $this->seedRosters($leagues);
        $this->seedPlayerScenarios($leagues);
    }

    /**
     * Teléfono de 10 dígitos numéricos, único porque la columna tiene índice único.
     */
    private function phone(): string
    {
        return fake()->unique()->numerify('##########');
    }

    private function seedSuperAdmin(): void
    {
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@example.com'],
            ['first_name' => 'Admin', 'last_name' => 'Demo', 'user_code' => 'ADMIN001', 'phone' => '5500000000', 'password' => 'password', 'photo_url' => 'https://placehold.co/400x400?text=AD']
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
                ['user_code' => 'LIGA001', 'phone' => $this->phone()],
                ['user_code' => 'LIGA002', 'phone' => $this->phone()],
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

            // Apertura a una vuelta y Clausura a dos; la duración es de 60 min salvo en el Clausura de la primera liga.
            foreach (['Torneo Apertura', 'Torneo Clausura'] as $index => $name) {
                Tournament::factory()->create([
                    'league_id' => $league->id,
                    'name' => $name,
                    'start_date' => $startDate,
                    'rounds' => $index + 1,
                    'match_duration_minutes' => $league->is($leagues[0]) && $index === 1 ? 50 : 60,
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
            ->sequence(fn ($sequence) => ['user_code' => sprintf('ARB%03d', $sequence->index + 1), 'phone' => $this->phone()])
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

    /**
     * 14 equipos por liga, con los delegados existentes repartidos en círculo; los 10 primeros se inscriben en
     * cada torneo de la liga y los otros 4 quedan disponibles. Un delegado puede llevar equipos de varias ligas.
     * Solo crea los equipos que faltan por nombre, así que completa una base ya sembrada.
     *
     * @param  list<League>  $leagues
     */
    private function seedTeams(array $leagues): void
    {
        $managerIds = User::query()
            ->whereHas('roles', fn ($q) => $q->where('roles.id', RoleEnum::MANAGER->value))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $next = 0;

        foreach ($leagues as $league) {
            $existing = $league->teams()->pluck('name')->all();

            foreach (array_diff(self::TEAM_NAMES, $existing) as $name) {
                Team::factory()->create([
                    'league_id' => $league->id,
                    'name' => $name,
                    'manager_user_id' => $managerIds === [] ? null : $managerIds[$next++ % count($managerIds)],
                ]);
            }

            $teams = $league->teams()->whereIn('name', array_slice(self::TEAM_NAMES, 0, self::TEAMS_PER_TOURNAMENT))->get();

            foreach ($league->tournaments()->get() as $tournament) {
                if ($tournament->registrations()->exists()) {
                    continue;
                }

                foreach ($teams as $team) {
                    Registration::factory()->create(['tournament_id' => $tournament->id, 'team_id' => $team->id]);
                }
            }
        }
    }

    /**
     * Torneo en planeación de la primera liga con 8 equipos en su grupo «Fase regular», para probar el reparto en
     * dos grupos, el reparto aleatorio y una liguilla. No hace nada si ya existe.
     *
     * @param  list<League>  $leagues
     */
    private function seedGroupsTournament(array $leagues): void
    {
        $league = $leagues[0] ?? null;

        if ($league === null || $league->tournaments()->where('name', 'Torneo de Grupos')->exists()) {
            return;
        }

        $tournament = Tournament::factory()->create([
            'league_id' => $league->id,
            'name' => 'Torneo de Grupos',
            'start_date' => now()->addMonths(2)->toDateString(),
        ]);

        foreach ($league->teams()->orderBy('id')->take(8)->get() as $team) {
            Registration::factory()->create(['tournament_id' => $tournament->id, 'team_id' => $team->id]);
        }
    }

    /**
     * Dos torneos de la primera liga para probar los bloqueos por estado: «Torneo en Fase Regular» (en curso) con una
     * liguilla sin fechas, y «Torneo en Liguilla» (estado liguilla, sin partidos) con una liguilla con fechas.
     * Cada uno tiene 4 equipos en su grupo «Fase regular». No hace nada si ya existen.
     *
     * @param  list<League>  $leagues
     */
    private function seedPlayoffStageTournaments(array $leagues): void
    {
        $league = $leagues[0] ?? null;

        if ($league === null) {
            return;
        }

        $scenarios = [
            ['Torneo en Fase Regular', TournamentStatus::IN_PROGRESS, null, null],
            ['Torneo en Liguilla', TournamentStatus::PLAYOFFS, ['2026-12-07', '2026-12-10', '2026-12-16'], [2, 1]],
        ];

        foreach ($scenarios as [$name, $status, $roundDates, $roundLegs]) {
            if ($league->tournaments()->where('name', $name)->exists()) {
                continue;
            }

            $tournament = Tournament::factory()->status($status)->create([
                'league_id' => $league->id,
                'name' => $name,
                'start_date' => now()->subMonth()->toDateString(),
            ]);
            $regular = $tournament->groups()->firstOrFail();

            foreach ($league->teams()->orderBy('id')->take(4)->get() as $team) {
                Registration::factory()->create(['tournament_id' => $tournament->id, 'team_id' => $team->id, 'group_id' => $regular->id]);
            }

            if ($status === TournamentStatus::PLAYOFFS) {
                $this->seedPlayedCalendar($tournament);
            }

            $playoff = new TournamentGroup;
            $playoff->forceFill([
                'tournament_id' => $tournament->id,
                'name' => 'Liguilla',
                'type' => TournamentGroupType::PLAYOFF,
                'start_date' => $roundDates[0] ?? null,
                'round_interval_days' => 7,
                'round_dates' => $roundDates,
                'round_legs' => $roundLegs,
            ])->save();

            $source = new PlayoffSource;
            $source->forceFill(['playoff_group_id' => $playoff->id, 'source_group_id' => $regular->id, 'position_start' => 1, 'position_end' => 4])->save();
        }
    }

    /**
     * «Torneo 4 Grupos» en planeación de la primera liga: Grupo A a D con 4 equipos cada uno (16 en total, sin vetados y
     * con delegado; crea los que falten) y una «Liguilla Principal» sin fechas con las posiciones 1 y 2 de cada grupo.
     * No lleva el grupo automático «Fase regular». No hace nada si ya existe.
     *
     * @param  list<League>  $leagues
     */
    private function seedFourGroupsTournament(array $leagues): void
    {
        $league = $leagues[0] ?? null;

        if ($league === null || $league->tournaments()->where('name', 'Torneo 4 Grupos')->exists()) {
            return;
        }

        $teams = $league->teams()->where('status', TeamStatus::ACTIVE)->whereNotNull('manager_user_id')->orderBy('id')->take(16)->get();
        $managerIds = $league->teams()->whereNotNull('manager_user_id')->pluck('manager_user_id')->unique()->values();

        for ($i = $teams->count() + 1; $i <= 16; $i++) {
            $teams->push(Team::factory()->create([
                'league_id' => $league->id,
                'name' => "Equipo Extra {$i}",
                'manager_user_id' => $managerIds->isEmpty() ? null : $managerIds[$i % $managerIds->count()],
            ]));
        }

        $tournament = Tournament::factory()->create([
            'league_id' => $league->id,
            'name' => 'Torneo 4 Grupos',
            'gender' => TournamentGender::MIXED,
            'min_age' => null,
            'max_age' => null,
            'start_date' => now()->addMonths(3)->toDateString(),
        ]);

        // El grupo automático sobra: este torneo trae los suyos.
        $tournament->groups()->where('name', TournamentGroup::DEFAULT_NAME)->delete();

        $groups = [];

        foreach (['Grupo A', 'Grupo B', 'Grupo C', 'Grupo D'] as $index => $name) {
            $group = new TournamentGroup;
            $group->forceFill(['tournament_id' => $tournament->id, 'name' => $name, 'type' => TournamentGroupType::REGULAR_STAGE])->save();
            $groups[] = $group;

            foreach ($teams->slice($index * 4, 4) as $team) {
                Registration::factory()->create(['tournament_id' => $tournament->id, 'team_id' => $team->id, 'group_id' => $group->id]);
            }
        }

        $playoff = new TournamentGroup;
        $playoff->forceFill([
            'tournament_id' => $tournament->id,
            'name' => 'Liguilla Principal',
            'type' => TournamentGroupType::PLAYOFF,
            'start_date' => null,
            'round_interval_days' => 7,
            'round_dates' => null,
            'round_legs' => [1, 2, 1],
        ])->save();

        foreach ($groups as $group) {
            $source = new PlayoffSource;
            $source->forceFill(['playoff_group_id' => $playoff->id, 'source_group_id' => $group->id, 'position_start' => 1, 'position_end' => 2])->save();
        }
    }

    /**
     * «Torneo Impar» en curso, doble vuelta, de la primera liga: Grupo A con 5 equipos y Grupo B con 4, sin partidos
     * ni liguillas (al generar: A = 10 jornadas y 20 partidos, B = 6 jornadas y 12, total 32). No hace nada si ya existe.
     *
     * @param  list<League>  $leagues
     */
    private function seedOddTournament(array $leagues): void
    {
        $league = $leagues[0] ?? null;

        if ($league === null || $league->tournaments()->where('name', 'Torneo Impar')->exists()) {
            return;
        }

        $tournament = Tournament::factory()->status(TournamentStatus::IN_PROGRESS)->create([
            'league_id' => $league->id,
            'name' => 'Torneo Impar',
            'gender' => TournamentGender::MIXED,
            'min_age' => null,
            'max_age' => null,
            'rounds' => 2,
            'min_matches_for_playoffs' => null,
            'start_date' => now()->subWeek()->toDateString(),
        ]);

        $tournament->groups()->where('name', TournamentGroup::DEFAULT_NAME)->delete();
        $teams = $league->teams()->where('status', TeamStatus::ACTIVE)->orderBy('id')->take(9)->get();

        foreach ([['Grupo A', 0, 5], ['Grupo B', 5, 4]] as [$name, $offset, $size]) {
            $group = new TournamentGroup;
            $group->forceFill(['tournament_id' => $tournament->id, 'name' => $name, 'type' => TournamentGroupType::REGULAR_STAGE])->save();

            foreach ($teams->slice($offset, $size) as $team) {
                Registration::factory()->create(['tournament_id' => $tournament->id, 'team_id' => $team->id, 'group_id' => $group->id]);
            }
        }
    }

    /**
     * Calendario regular completo del torneo con todos los partidos jugados y sin empates totales: el equipo de
     * inscripción más antigua gana a todos, el siguiente a los demás, etc. (tabla final de 9, 6, 3 y 0 puntos).
     */
    private function seedPlayedCalendar(Tournament $tournament): void
    {
        $status = $tournament->status;
        $tournament->forceFill(['status' => TournamentStatus::IN_PROGRESS])->save();
        app(MatchServiceInterface::class)->generate($tournament, false);
        $tournament->forceFill(['status' => $status])->save();

        $strength = $tournament->registrations()->orderBy('id')->pluck('id')->flip();

        foreach (GameMatch::query()->where('tournament_id', $tournament->id)->get() as $match) {
            $homeWins = $strength[$match->home_registration_id] < $strength[$match->away_registration_id];

            $match->forceFill([
                'status' => MatchStatus::PLAYED,
                'home_score' => $homeWins ? 2 : 0,
                'away_score' => $homeWins ? 0 : 2,
            ])->save();
        }
    }

    /**
     * «Torneo Mínimos» en curso: un grupo de 4 equipos, una vuelta y 6 partidos mínimos para liguilla (cada equipo
     * jugará 3), sin calendario: generarlo se rechaza por los partidos mínimos. No hace nada si ya existe.
     *
     * @param  list<League>  $leagues
     */
    private function seedMinimumsTournament(array $leagues): void
    {
        $league = $leagues[0] ?? null;

        if ($league === null || $league->tournaments()->where('name', 'Torneo Mínimos')->exists()) {
            return;
        }

        $tournament = Tournament::factory()->status(TournamentStatus::IN_PROGRESS)->create([
            'league_id' => $league->id,
            'name' => 'Torneo Mínimos',
            'gender' => TournamentGender::MIXED,
            'min_age' => null,
            'max_age' => null,
            'rounds' => 1,
            'min_matches_for_playoffs' => 6,
            'start_date' => now()->subWeek()->toDateString(),
        ]);
        $group = $tournament->groups()->firstOrFail();

        foreach ($league->teams()->where('status', TeamStatus::ACTIVE)->orderBy('id')->take(4)->get() as $team) {
            Registration::factory()->create(['tournament_id' => $tournament->id, 'team_id' => $team->id, 'group_id' => $group->id]);
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
            ->sequence(fn ($sequence) => [
                'user_code' => sprintf('%s%03d', $codePrefix, $existing + $sequence->index + 1),
                'phone' => $this->phone(),
            ])
            ->create()
            ->each(fn (User $user) => $user->roles()->sync([$role->value]));
    }

    /**
     * Perfil de jugador (fecha de nacimiento, rama y QR) para cada usuario con rol jugador que aún no lo tiene;
     * la rama se alterna para tener de ambas.
     */
    private function seedPlayers(): void
    {
        $users = User::query()
            ->whereHas('roles', fn ($q) => $q->where('roles.id', RoleEnum::PLAYER->value))
            ->whereDoesntHave('player')
            ->orderBy('id')
            ->get();

        foreach ($users as $index => $user) {
            Player::factory()
                ->gender($index % 2 === 0 ? PlayerGender::MALE : PlayerGender::FEMALE)
                ->create(['user_id' => $user->id]);
        }
    }

    /**
     * Plantillas de la primera liga para probar el panel. Torneo Apertura (máximo de 12 jugadores): el primer equipo
     * queda en 11 de 12 con filas en todos los estados y el segundo tiene un roster corto. Torneo Clausura: un equipo
     * con todos los estados. Torneo Relámpago, en curso: dos equipos con plantilla activa. Los jugadores no se
     * repiten dentro de un torneo. No hace nada si ya hay rosters.
     *
     * @param  list<League>  $leagues
     */
    private function seedRosters(array $leagues): void
    {
        $league = $leagues[0] ?? null;

        if ($league === null || Roster::query()->exists()) {
            return;
        }

        $players = Player::query()->orderBy('id')->get();
        $admin = $league->admin;

        if ($players->isEmpty() || $admin === null) {
            return;
        }

        $cursor = 0;
        $rows = function (Registration $registration, array $plan) use ($players, $admin, &$cursor): void {
            $team = $registration->team;
            $requestedBy = $team->manager_user_id ?? $admin->id;
            $jersey = 0;

            foreach ($plan as $entry) {
                [$status, $count] = $entry;

                for ($i = 0; $i < $count; $i++) {
                    $row = new Roster;
                    $row->forceFill([
                        'registration_id' => $registration->id,
                        'tournament_id' => $registration->tournament_id,
                        'player_id' => $players[$cursor++ % $players->count()]->id,
                        'jersey_number' => ++$jersey,
                        'status' => $status,
                        'requested_by_user_id' => $requestedBy,
                        'requested_at' => now()->subDays(10),
                        'responded_at' => $status === RosterStatus::PENDING ? null : now()->subDays(9),
                    ]);

                    if ($status === RosterStatus::REMOVED) {
                        $row->forceFill([
                            'removed_by_user_id' => $admin->id,
                            'removed_at' => now()->subDays(2),
                            'removal_reason' => $entry[2] ?? 'Baja solicitada por el delegado',
                            // El segundo removido de la lista ya tiene el reingreso autorizado.
                            'rejoin_authorized_at' => ($entry[3] ?? false) ? now()->subDay() : null,
                        ]);
                    }

                    $row->save();
                }
            }
        };

        $all = [
            [RosterStatus::REJECTED, 1],
            [RosterStatus::CANCELLED, 1],
            [RosterStatus::REMOVED, 1, 'Doble inscripción en otro equipo'],
            [RosterStatus::REMOVED, 1, 'Cambio de ciudad', true],
        ];

        $apertura = $league->tournaments()->where('name', 'Torneo Apertura')->first();

        if ($apertura !== null) {
            $apertura->forceFill(['max_roster_size' => 12])->save();
            $registrations = $apertura->registrations()->with('team')->orderBy('id')->get();

            if ($registrations->count() >= 2) {
                $rows($registrations[0], [[RosterStatus::ACTIVE, 8], [RosterStatus::PENDING, 3], ...$all]);
                $rows($registrations[1], [[RosterStatus::ACTIVE, 3], [RosterStatus::PENDING, 1]]);
            }
        }

        $clausura = $league->tournaments()->where('name', 'Torneo Clausura')->first();
        $clausuraRegistration = $clausura?->registrations()->with('team')->orderBy('id')->first();

        if ($clausuraRegistration !== null) {
            $rows($clausuraRegistration, [[RosterStatus::ACTIVE, 6], [RosterStatus::PENDING, 2], ...$all]);
        }

        $this->seedTournamentInProgress($league, $rows);
    }

    /**
     * Torneo en curso con dos equipos y plantilla activa.
     *
     * @param  callable(Registration, list<array<int, mixed>>): void  $rows
     */
    private function seedTournamentInProgress(League $league, callable $rows): void
    {
        $tournament = Tournament::factory()->create([
            'league_id' => $league->id,
            'name' => 'Torneo Relámpago',
            'start_date' => now()->subWeek()->toDateString(),
            'status' => TournamentStatus::IN_PROGRESS,
        ]);

        $teams = $league->teams()->orderBy('id')->skip(10)->take(2)->get();

        foreach ($teams as $team) {
            $registration = Registration::factory()->create(['tournament_id' => $tournament->id, 'team_id' => $team->id]);
            $rows($registration->load('team'), [[RosterStatus::ACTIVE, 7]]);
        }
    }

    /**
     * Casos para probar el listado de jugadores, la rama y el veto, con los últimos jugadores sembrados (los
     * rosters de arriba usan los primeros): uno vetado y uno con veto levantado en la primera liga; un varón con
     * roster activo en un torneo varonil (bloquea cambiar la rama) y otro con roster solo en un torneo mixto; uno con
     * rosters en dos ligas; edades distintas, algunos sin foto; y varones con solicitud pending (dos en la Copa Varonil y uno
     * en un torneo mixto) para probar que al corregir la rama a femenil las pending del torneo varonil pasan a
     * cancelled y la del mixto se conserva. No hace nada si ya hay vetos.
     *
     * @param  list<League>  $leagues
     */
    private function seedPlayerScenarios(array $leagues): void
    {
        $league = $leagues[0] ?? null;
        $other = $leagues[1] ?? null;

        if ($league === null || $other === null || PlayerLeagueBan::query()->exists()) {
            return;
        }

        $players = Player::query()->with('user')->orderByDesc('id')->take(11)->get()->values();
        $admin = $league->admin;
        $registrations = fn (Tournament $tournament) => $tournament->registrations()->with('team')->orderBy('id')->get();

        if ($players->count() < 11 || $admin === null) {
            return;
        }

        [$banned, $lifted, $maleActive, $mixedOnly, $twoLeagues, $noPhoto, $older, $young, $pendingTigres, $pendingHalcones, $pendingMixed] = $players->all();

        // Edades y fotos distintas.
        foreach ([[$noPhoto, '2001-03-10'], [$older, '1978-08-21'], [$young, now()->subYears(19)->toDateString()]] as [$player, $birth]) {
            $player->forceFill(['birth_date' => $birth])->save();
        }
        $noPhoto->user?->forceFill(['photo_url' => null])->save();
        $older->user?->forceFill(['photo_url' => null])->save();

        foreach ([$banned, $lifted] as $player) {
            $ban = new PlayerLeagueBan;
            $ban->forceFill(['player_id' => $player->id, 'league_id' => $league->id, 'banned_by_user_id' => $admin->id, 'reason' => 'Agresión al árbitro en la jornada 3'])->save();
        }
        PlayerLeagueBan::query()->where('player_id', $lifted->id)->update(['lifted_at' => now()->subDay(), 'lifted_by_user_id' => $admin->id]);

        $maleActive->forceFill(['gender' => PlayerGender::MALE])->save();
        $twoLeagues->forceFill(['gender' => PlayerGender::MALE])->save();

        foreach ([$pendingTigres, $pendingHalcones, $pendingMixed] as $player) {
            $player->forceFill(['gender' => PlayerGender::MALE])->save();
        }

        $varonil = Tournament::factory()->create([
            'league_id' => $league->id,
            'name' => 'Copa Varonil',
            'gender' => TournamentGender::MALE,
            'start_date' => now()->addMonth()->toDateString(),
        ]);
        $teams = $league->teams()->orderBy('id')->take(2)->get();

        foreach ($teams as $team) {
            Registration::factory()->create(['tournament_id' => $varonil->id, 'team_id' => $team->id]);
        }

        $varonilRegistration = $registrations($varonil)->first();
        $leagueTournaments = $league->tournaments()->where('name', 'Torneo Clausura')->first();
        $mixedRegistrations = $leagueTournaments === null ? collect() : $registrations($leagueTournaments);
        $otherTournament = $other->tournaments()->orderBy('id')->first();
        $otherRegistration = $otherTournament === null ? null : $registrations($otherTournament)->first();

        $rows = [
            [$varonilRegistration, $maleActive, 40],
            [$registrations($varonil)->get(1), $pendingTigres, 50, RosterStatus::PENDING],
            [$varonilRegistration, $pendingHalcones, 51, RosterStatus::PENDING],
            [$mixedRegistrations->get(0), $pendingMixed, 52, RosterStatus::PENDING],
            [$mixedRegistrations->get(1), $mixedOnly, 41],
            [$mixedRegistrations->get(1), $twoLeagues, 42],
            [$otherRegistration, $twoLeagues, 43],
        ];

        foreach ($rows as $entry) {
            [$registration, $player, $jersey] = $entry;
            $status = $entry[3] ?? RosterStatus::ACTIVE;

            if ($registration === null) {
                continue;
            }

            $row = new Roster;
            $row->forceFill([
                'registration_id' => $registration->id,
                'tournament_id' => $registration->tournament_id,
                'player_id' => $player->id,
                'jersey_number' => $jersey,
                'status' => $status,
                'requested_by_user_id' => $registration->team->manager_user_id ?? $admin->id,
                'requested_at' => now()->subDays(5),
                'responded_at' => $status === RosterStatus::PENDING ? null : now()->subDays(4),
            ])->save();
        }
    }
}
