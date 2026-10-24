<?php

namespace Database\Seeders;

use App\Contracts\Services\MatchServiceInterface;
use App\Contracts\Services\PlayoffServiceInterface;
use App\Core\Enum\MatchStatus;
use App\Core\Enum\PlayerGender;
use App\Core\Enum\RegistrationStatus;
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
use App\Models\PlayoffTie;
use App\Models\Registration;
use App\Models\Roster;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use App\Models\User;
use App\Services\PlayerEligibility;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class DevelopmentSeeder extends Seeder
{
    private const REFEREES = 10;

    private const REFEREES_PER_LEAGUE = 3;

    private const MIN_FIELDS_PER_LEAGUE = 1;

    private const MAX_FIELDS_PER_LEAGUE = 3;

    private const MANAGERS = 15;

    private const PLAYERS = 100;

    /**
     * Plantilla de cada equipo inscrito: filas de roster por estado (jugadores distintos entre sí).
     *
     * @var array<string, int>
     */
    private const ROSTER_PLAN = ['active' => 10, 'pending' => 2, 'rejected' => 2, 'cancelled' => 2, 'removed' => 1];

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
        $this->seedToCloseTournament($leagues);
        $this->seedTiedTournament($leagues);
        $this->seed25TeamsTournaments($leagues);
        $this->seedUsersWithRole(RoleEnum::PLAYER, self::PLAYERS, 'JUG');
        $this->seedPlayers();
        $this->seedRosters($leagues);
        $this->seedPlayerScenarios($leagues);
        $this->seedTeamRosters($leagues);
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
     * Dos torneos de la primera liga con 4 equipos en su grupo «Fase regular»: «Torneo en Fase Regular» (en curso, sin
     * calendario) con una liguilla a un partido por ronda y sin fechas, y «Torneo en Liguilla», con la fase regular jugada
     * y cerrada: liguilla [2, 1] con fechas, semifinal 1 definida por penales (ida con victoria clara, vuelta con global
     * empatado), semifinal 2 con la ida jugada y la vuelta pendiente, y la final con un equipo ya avanzado.
     * No hace nada si ya existen.
     *
     * @param  list<League>  $leagues
     */
    private function seedPlayoffStageTournaments(array $leagues): void
    {
        $league = $leagues[0] ?? null;

        if ($league === null) {
            return;
        }

        foreach (['Torneo en Fase Regular', 'Torneo en Liguilla'] as $name) {
            if ($league->tournaments()->where('name', $name)->exists()) {
                continue;
            }

            $closed = $name === 'Torneo en Liguilla';
            $tournament = Tournament::factory()->status(TournamentStatus::IN_PROGRESS)->create([
                'league_id' => $league->id,
                'name' => $name,
                'start_date' => now()->subMonth()->toDateString(),
            ]);
            $regular = $tournament->groups()->firstOrFail();

            foreach ($league->teams()->orderBy('id')->take(4)->get() as $team) {
                Registration::factory()->create(['tournament_id' => $tournament->id, 'team_id' => $team->id, 'group_id' => $regular->id]);
            }

            if ($closed) {
                $this->seedPlayedCalendar($tournament);
            }

            $playoff = $this->seedPlayoff($tournament, 'Liguilla', [[$regular, 1, 4]], $closed ? [2, 1] : null);

            if ($closed) {
                app(PlayoffServiceInterface::class)->close($tournament, [[
                    'group_id' => $playoff->id,
                    'round_dates' => ['2026-12-07', '2026-12-10', '2026-12-16'],
                ]]);
                $this->seedSemifinalResults($tournament->refresh(), $playoff);
            }
        }
    }

    /**
     * @param  list<array{0: TournamentGroup, 1: int, 2: int}>  $sources  grupo de origen, posición inicial y final
     * @param  list<int>|null  $roundLegs
     */
    private function seedPlayoff(Tournament $tournament, string $name, array $sources, ?array $roundLegs): TournamentGroup
    {
        $playoff = new TournamentGroup;
        $playoff->forceFill([
            'tournament_id' => $tournament->id,
            'name' => $name,
            'type' => TournamentGroupType::PLAYOFF,
            'start_date' => null,
            'round_interval_days' => 7,
            'round_dates' => null,
            'round_legs' => $roundLegs,
        ])->save();

        foreach ($sources as [$group, $from, $to]) {
            $source = new PlayoffSource;
            $source->forceFill(['playoff_group_id' => $playoff->id, 'source_group_id' => $group->id, 'position_start' => $from, 'position_end' => $to])->save();
        }

        return $playoff;
    }

    /**
     * Semifinal 1 (1 vs 4): ida 0-3 a favor del mejor sembrado, vuelta 0-3 para el otro (global 3-3) y penales 4-5.
     * Semifinal 2 (2 vs 3): ida 1-1 y vuelta pendiente.
     */
    private function seedSemifinalResults(Tournament $tournament, TournamentGroup $playoff): void
    {
        $matches = app(MatchServiceInterface::class);
        $tie = fn (int $position) => PlayoffTie::query()->where('group_id', $playoff->id)->where('round_index', 1)->where('bracket_position', $position)->firstOrFail();

        [$leg1, $leg2] = $tie(1)->matches->all();
        $matches->recordResult($tournament, $leg1, 0, 3);
        $matches->recordResult($tournament, $leg2, 0, 3, 4, 5);

        $matches->recordResult($tournament, $tie(2)->matches->firstOrFail(), 1, 1);
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
     * Calendario regular completo del torneo con todos los partidos jugados. Por defecto el equipo de inscripción más
     * antigua gana 2-0 a los demás, el siguiente a los que le siguen, etc. (sin empates entre equipos). `$score` recibe
     * el orden de inscripción (0 = más antigua) del local y del visitante y devuelve `[goles local, goles visitante]`.
     *
     * @param  (callable(int, int): array{0: int, 1: int})|null  $score
     */
    private function seedPlayedCalendar(Tournament $tournament, ?callable $score = null): void
    {
        $status = $tournament->status;
        $tournament->forceFill(['status' => TournamentStatus::IN_PROGRESS])->save();
        app(MatchServiceInterface::class)->generate($tournament, false);
        $tournament->forceFill(['status' => $status])->save();

        $strength = $tournament->registrations()->orderBy('id')->pluck('id')->flip();
        $score ??= fn (int $home, int $away) => $home < $away ? [2, 0] : [0, 2];

        foreach (GameMatch::query()->where('tournament_id', $tournament->id)->get() as $match) {
            [$homeGoals, $awayGoals] = $score($strength[$match->home_registration_id], $strength[$match->away_registration_id]);

            $match->forceFill(['status' => MatchStatus::PLAYED, 'home_score' => $homeGoals, 'away_score' => $awayGoals])->save();
        }
    }

    /**
     * «Torneo por Cerrar» en curso: un grupo de 8 equipos a una vuelta con todos los partidos jugados y sin empates sin
     * resolver, y una liguilla de las posiciones 1 a 8 con round_legs [1, 2, 1] sin fechas. No hace nada si ya existe.
     *
     * @param  list<League>  $leagues
     */
    private function seedToCloseTournament(array $leagues): void
    {
        $league = $leagues[0] ?? null;

        if ($league === null || $league->tournaments()->where('name', 'Torneo por Cerrar')->exists()) {
            return;
        }

        $tournament = Tournament::factory()->status(TournamentStatus::IN_PROGRESS)->create([
            'league_id' => $league->id,
            'name' => 'Torneo por Cerrar',
            'gender' => TournamentGender::MIXED,
            'min_age' => null,
            'max_age' => null,
            'rounds' => 1,
            'start_date' => now()->subMonths(2)->toDateString(),
        ]);
        $group = $tournament->groups()->firstOrFail();

        foreach ($league->teams()->where('status', TeamStatus::ACTIVE)->orderBy('id')->take(8)->get() as $team) {
            Registration::factory()->create(['tournament_id' => $tournament->id, 'team_id' => $team->id, 'group_id' => $group->id]);
        }

        $this->seedPlayedCalendar($tournament);
        $this->seedPlayoff($tournament, 'Liguilla', [[$group, 1, 8]], [1, 2, 1]);
    }

    /**
     * «Torneo con Empate» en curso: 4 equipos con la fase regular jugada, donde el 2.º y el 3.º quedan empatados en
     * todo (puntos, diferencia, goles y resultado directo) y la liguilla clasifica a los dos primeros: cerrar la fase
     * se bloquea por el empate. No hace nada si ya existe.
     *
     * @param  list<League>  $leagues
     */
    private function seedTiedTournament(array $leagues): void
    {
        $league = $leagues[0] ?? null;

        if ($league === null || $league->tournaments()->where('name', 'Torneo con Empate')->exists()) {
            return;
        }

        $tournament = Tournament::factory()->status(TournamentStatus::IN_PROGRESS)->create([
            'league_id' => $league->id,
            'name' => 'Torneo con Empate',
            'gender' => TournamentGender::MIXED,
            'min_age' => null,
            'max_age' => null,
            'rounds' => 1,
            'start_date' => now()->subMonths(2)->toDateString(),
        ]);
        $group = $tournament->groups()->firstOrFail();

        foreach ($league->teams()->where('status', TeamStatus::ACTIVE)->orderBy('id')->take(4)->get() as $team) {
            Registration::factory()->create(['tournament_id' => $tournament->id, 'team_id' => $team->id, 'group_id' => $group->id]);
        }

        // El primero gana 1-0 a todos; el 2.º y el 3.º ganan 1-0 al 4.º y empatan 0-0 entre sí.
        $this->seedPlayedCalendar($tournament, function (int $home, int $away): array {
            $stronger = min($home, $away);

            if ($stronger === 0 || ($stronger !== 3 && max($home, $away) === 3)) {
                return $home < $away ? [1, 0] : [0, 1];
            }

            return [0, 0];
        });
        $this->seedPlayoff($tournament, 'Liguilla', [[$group, 1, 2]], null);
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

    /**
     * Tres torneos de la primera liga con 5 grupos de 5 equipos (25) y una liguilla de las posiciones 1 a 3 de cada grupo
     * (15) más el mejor 4.º entre los grupos (16), round_legs [1, 1, 1, 1] y sin fechas: «Torneo 25 Equipos» en planeación,
     * «Torneo 25 por Cerrar» en curso con todo jugado y un mejor 4.º claro (el del último grupo gana su partido con más
     * goles), y «Torneo 25 con Empate» en curso con todo jugado y los cinco 4.º empatados (el empate cruza el corte).
     * No hace nada con los que ya existen.
     *
     * @param  list<League>  $leagues
     */
    private function seed25TeamsTournaments(array $leagues): void
    {
        $league = $leagues[0] ?? null;

        if ($league === null) {
            return;
        }

        $scenarios = [
            ['Torneo 25 Equipos', TournamentStatus::PLANNING, null],
            ['Torneo 25 por Cerrar', TournamentStatus::IN_PROGRESS, function (int $home, int $away): array {
                $lower = min($home, $away);
                $margin = ($lower % 5 === 3 && max($home, $away) % 5 === 4) ? intdiv($lower, 5) + 2 : 2;

                return $home < $away ? [$margin, 0] : [0, $margin];
            }],
            ['Torneo 25 con Empate', TournamentStatus::IN_PROGRESS, fn (int $home, int $away): array => $home < $away ? [2, 0] : [0, 2]],
        ];

        foreach ($scenarios as [$name, $status, $score]) {
            if ($league->tournaments()->where('name', $name)->exists()) {
                continue;
            }

            $teams = $this->leagueTeams($league, 25);
            $tournament = Tournament::factory()->status($status)->create([
                'league_id' => $league->id,
                'name' => $name,
                'gender' => TournamentGender::MIXED,
                'min_age' => null,
                'max_age' => null,
                'rounds' => 1,
                'start_date' => now()->addMonths($status === TournamentStatus::PLANNING ? 3 : -2)->toDateString(),
            ]);
            $tournament->groups()->where('name', TournamentGroup::DEFAULT_NAME)->delete();

            $groups = [];

            foreach (['Grupo A', 'Grupo B', 'Grupo C', 'Grupo D', 'Grupo E'] as $index => $groupName) {
                $group = new TournamentGroup;
                $group->forceFill(['tournament_id' => $tournament->id, 'name' => $groupName, 'type' => TournamentGroupType::REGULAR_STAGE])->save();
                $groups[] = $group;

                foreach ($teams->slice($index * 5, 5) as $team) {
                    Registration::factory()->create(['tournament_id' => $tournament->id, 'team_id' => $team->id, 'group_id' => $group->id]);
                }
            }

            $playoff = $this->seedPlayoff($tournament, 'Liguilla', array_map(fn (TournamentGroup $group) => [$group, 1, 3], $groups), [1, 1, 1, 1]);
            $best = new PlayoffSource;
            $best->forceFill([
                'playoff_group_id' => $playoff->id,
                'kind' => PlayoffSource::KIND_BEST_OF_POSITION,
                'source_group_id' => null,
                'position_start' => 4,
                'position_end' => 4,
                'best_count' => 1,
            ])->save();

            if ($score !== null) {
                $this->seedPlayedCalendar($tournament, $score);
            }
        }
    }

    /**
     * Los primeros $count equipos activos de la liga (con delegado primero); crea «Equipo Extra n» si faltan.
     *
     * @return Collection<int, Team>
     */
    private function leagueTeams(League $league, int $count): Collection
    {
        $teams = $league->teams()->where('status', TeamStatus::ACTIVE)->whereNotNull('manager_user_id')->orderBy('id')->take($count)->get()->values();
        $managerIds = $league->teams()->whereNotNull('manager_user_id')->pluck('manager_user_id')->unique()->values();
        $extra = 1;

        while ($teams->count() < $count) {
            $name = 'Equipo Extra '.($teams->count() + $extra);
            $team = $league->teams()->where('name', $name)->first() ?? Team::factory()->create([
                'league_id' => $league->id,
                'name' => $name,
                'manager_user_id' => $managerIds->isEmpty() ? null : $managerIds[$teams->count() % $managerIds->count()],
            ]);

            if ($teams->contains('id', $team->id)) {
                $extra++;

                continue;
            }

            $teams->push($team);
        }

        return $teams;
    }

    /**
     * Plantilla de {@see self::ROSTER_PLAN} (10 activos, 2 pendientes, 2 rechazados, 2 cancelados y 1 removido) en cada
     * inscripción activa que todavía no tiene filas de roster (las plantillas de prueba de arriba se respetan), para
     * simular jugadores ya ligados a un equipo y un torneo. Los jugadores salen de los ya sembrados, así que juegan en varios torneos (y en varias ligas); en un mismo
     * torneo nunca se repiten, cumplen rama y edad y no tienen veto vigente en la liga. Si no alcanzan, crea jugadores
     * nuevos. No repite filas al volver a correr.
     *
     * @param  list<League>  $leagues
     */
    private function seedTeamRosters(array $leagues): void
    {
        $eligibility = new PlayerEligibility;
        $cursor = 0;

        foreach ($leagues as $league) {
            $admin = $league->admin;

            if ($admin === null) {
                continue;
            }

            $banned = PlayerLeagueBan::query()->where('league_id', $league->id)->whereNull('lifted_at')->pluck('player_id')->all();

            foreach ($league->tournaments()->orderBy('id')->get() as $tournament) {
                $registrations = $tournament->registrations()
                    ->where('status', RegistrationStatus::ACTIVE)
                    ->whereDoesntHave('rosters')
                    ->with('team')
                    ->orderBy('id')
                    ->get();

                if ($registrations->isEmpty()) {
                    continue;
                }

                $taken = Roster::query()->where('tournament_id', $tournament->id)->where('status', RosterStatus::ACTIVE)->pluck('player_id')->all();
                $perTeam = array_sum(self::ROSTER_PLAN);
                $needed = $registrations->count() * $perTeam;
                $pool = $this->eligiblePlayers($tournament, $eligibility, $banned, $taken);

                for ($attempt = 0; $pool->count() < $needed && $attempt < 5; $attempt++) {
                    $this->addPlayers(($needed - $pool->count()) * 2);
                    $pool = $this->eligiblePlayers($tournament, $eligibility, $banned, $taken);
                }

                if ($pool->count() < $needed) {
                    continue;
                }

                // Se empieza en un punto distinto de la lista en cada torneo para que los jugadores se repartan entre ellos.
                $offset = $cursor % $pool->count();
                $rotated = $pool->slice($offset)->concat($pool->take($offset))->values();
                $cursor += $needed;
                $rows = [];

                foreach ($registrations as $index => $registration) {
                    $players = $rotated->slice($index * $perTeam, $perTeam)->values();
                    $number = 0;

                    foreach (self::ROSTER_PLAN as $status => $count) {
                        for ($i = 0; $i < $count; $i++) {
                            $rows[] = $this->rosterRow($registration, $players[$number], ++$number, $status, $admin->id);
                        }
                    }
                }

                foreach (array_chunk($rows, 500) as $chunk) {
                    Roster::query()->insert($chunk);
                }
            }
        }
    }

    /**
     * Fila de roster de un estado: las pendientes no tienen respuesta y la removida lleva quién, cuándo y por qué.
     *
     * @return array<string, mixed>
     */
    private function rosterRow(Registration $registration, Player $player, int $jersey, string $status, int $adminId): array
    {
        $pending = $status === RosterStatus::PENDING->value;
        $removed = $status === RosterStatus::REMOVED->value;
        $requestedDays = match ($status) {
            RosterStatus::PENDING->value => 2,
            RosterStatus::ACTIVE->value, RosterStatus::REMOVED->value => 20,
            default => 12,
        };

        return [
            'registration_id' => $registration->id,
            'tournament_id' => $registration->tournament_id,
            'player_id' => $player->id,
            'jersey_number' => $jersey,
            'status' => $status,
            'requested_by_user_id' => $registration->team->manager_user_id ?? $adminId,
            'requested_at' => now()->subDays($requestedDays),
            'responded_at' => $pending ? null : now()->subDays($requestedDays - 1),
            'removed_by_user_id' => $removed ? $adminId : null,
            'removed_at' => $removed ? now()->subDays(3) : null,
            'removal_reason' => $removed ? 'Baja solicitada por el delegado' : null,
            'rejoin_authorized_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Jugadores que cumplen rama y edad del torneo, sin veto vigente en la liga y que no están activos en el torneo.
     *
     * @param  list<int>  $banned
     * @param  list<int>  $taken
     * @return Collection<int, Player>
     */
    private function eligiblePlayers(Tournament $tournament, PlayerEligibility $eligibility, array $banned, array $taken): Collection
    {
        return Player::query()->orderBy('id')->get()
            ->reject(fn (Player $player) => in_array($player->id, $banned, true) || in_array($player->id, $taken, true))
            ->filter(fn (Player $player) => $eligibility->isEligible($player, $tournament))
            ->values();
    }

    /**
     * Crea $count jugadores nuevos (usuario con rol jugador y perfil), con ramas alternadas.
     */
    private function addPlayers(int $count): void
    {
        $existing = User::query()->whereHas('roles', fn ($q) => $q->where('roles.id', RoleEnum::PLAYER->value))->count();

        $this->seedUsersWithRole(RoleEnum::PLAYER, $existing + $count, 'JUG');
        $this->seedPlayers();
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
