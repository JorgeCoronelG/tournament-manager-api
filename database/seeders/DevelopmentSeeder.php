<?php

namespace Database\Seeders;

use App\Contracts\Repositories\ScheduleRepositoryInterface;
use App\Contracts\Services\MatchServiceInterface;
use App\Contracts\Services\PlayoffScheduleServiceInterface;
use App\Contracts\Services\PlayoffServiceInterface;
use App\Contracts\Services\RegistrationChangeServiceInterface;
use App\Contracts\Services\ScheduleGeneratorServiceInterface;
use App\Core\Enum\FieldStatus;
use App\Core\Enum\LeagueRefereeStatus;
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
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DevelopmentSeeder extends Seeder
{
    private const REFEREES = 10;

    private const REFEREES_PER_LEAGUE = 3;

    private const FIELDS_PER_LEAGUE = 3;

    private const MANAGERS = 15;

    private const DEMO_LEAGUE_PREFIX = 'Liga Demo ';

    private const PLAYERS = 100;

    /**
     * Plantilla de cada equipo inscrito: filas de roster por estado (jugadores distintos entre sí).
     *
     * @var array<string, int>
     */
    private const ROSTER_PLAN = ['active' => 10, 'pending' => 2, 'rejected' => 2, 'cancelled' => 2, 'removed' => 1];

    /** Inscritos por torneo: los primeros de TEAM_NAMES; el resto queda libre para probar la inscripción. */
    private const TEAMS_PER_TOURNAMENT = 10;

    /**
     * Ventanas semanales (día ISO, inicio, fin) que se aplican a todas las canchas de la liga demo. Cada torneo de una
     * misma liga usa un patrón distinto (días distintos), así no chocan entre sí.
     *
     * @var list<list<array{0: int, 1: string, 2: string}>>
     */
    private const WINDOW_PATTERNS = [
        [[6, '08:00', '14:00'], [7, '08:00', '12:00']],
        [[5, '17:00', '22:00'], [3, '17:00', '22:00']],
        [[2, '17:00', '22:00'], [4, '17:00', '22:00']],
    ];

    /**
     * Ligas demo: canchas, equipos propios y escenarios de torneo (el índice es el patrón de ventanas).
     *
     * @var array<int, array{fields: int, teams: int, tournaments: list<string>}>
     */
    private const DEMO_LEAGUES = [
        1 => ['fields' => 3, 'teams' => 8, 'tournaments' => ['regular', 'playoffs', 'groups']],
        2 => ['fields' => 2, 'teams' => 16, 'tournaments' => ['four_groups', 'to_close', 'tied']],
        3 => ['fields' => 4, 'teams' => 8, 'tournaments' => ['finished', 'two_playoffs', 'withdrawal']],
        4 => ['fields' => 3, 'teams' => 25, 'tournaments' => ['t25_planning', 't25_to_close']],
        5 => ['fields' => 2, 'teams' => 25, 'tournaments' => ['t25_tied', 'minimums']],
    ];

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
        $this->seedOddTournament($leagues);
        $this->seedUsersWithRole(RoleEnum::PLAYER, self::PLAYERS, 'JUG');
        $this->seedPlayers();
        $this->seedRosters($leagues);
        $this->seedPlayerScenarios($leagues);
        $this->seedTeamRosters($leagues);
        $this->seedSchedulingScenarios($leagues);
        $this->seedRoleTestUsers($leagues);
        $this->seedDemoLeagues();
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
        // Las ligas demo (ver seedDemoLeagues) son aparte: no cuentan como ligas base.
        $base = League::query()->where('name', 'not like', self::DEMO_LEAGUE_PREFIX.'%')->with('admin')->orderBy('id');

        if ($base->exists()) {
            return $base->get()->all();
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

            // Las canchas son de la liga, no del torneo: 3 activas por liga.
            for ($number = 1; $number <= self::FIELDS_PER_LEAGUE; $number++) {
                Field::factory()->create(['league_id' => $league->id, 'name' => "Cancha {$number}"]);
            }
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

        // Calendario de emparejamientos sin horarios generados: el flujo completo se prueba desde el generador.
        app(MatchServiceInterface::class)->generate($tournament, false);
        $this->seedScheduleConfig($tournament, $league);
    }

    /**
     * Horarios de «Torneo Impar» (60 min) desde el próximo lunes: sábado 8:00-14:00 en las canchas 1 y 2 (la 2 partida en
     * 8:00-9:00 y 10:00-14:00), domingo 8:00-12:00 en las canchas 1, 2 y 3, y un bloqueo de todo el día en la quinta
     * semana. Usa las canchas activas de la liga por orden (Cancha 1, 2 y 3).
     */
    private function seedScheduleConfig(Tournament $tournament, League $league): void
    {
        $fields = $league->fields()->where('status', FieldStatus::ACTIVE)->orderBy('id')->take(3)->get();
        $monday = now()->next(CarbonInterface::MONDAY)->startOfDay()->toImmutable();
        $saturday = 6;
        $sunday = 7;
        $windows = [];

        foreach ($fields as $index => $field) {
            if ($index < 2) {
                $saturdayRanges = $index === 1 ? [['08:00', '09:00'], ['10:00', '14:00']] : [['08:00', '14:00']];

                foreach ($saturdayRanges as [$start, $end]) {
                    $windows[] = ['weekday' => $saturday, 'field_id' => $field->id, 'start_time' => $start, 'end_time' => $end];
                }
            }

            $windows[] = ['weekday' => $sunday, 'field_id' => $field->id, 'start_time' => '08:00', 'end_time' => '12:00'];
        }

        $blocks = [[
            'date' => $monday->addWeeks(4)->addDays($saturday - 1)->toDateString(),
            'field_id' => null,
            'start_time' => null,
            'end_time' => null,
            'reason' => 'Día festivo',
        ]];

        app(ScheduleRepositoryInterface::class)->replace($tournament, $monday->toDateString(), $windows, $blocks);

        // Un partido ya fijado (sábado de la primera semana, 8:00 en la Cancha 1) para probar que el generador no lo mueve.
        $first = $fields->first();
        $locked = $tournament->matches()->where('matchday', 1)->orderBy('id')->first();

        if ($first !== null && $locked !== null) {
            $locked->forceFill([
                'match_datetime' => $monday->addDays($saturday - 1)->setTime(8, 0)->toDateTimeString(),
                'field_id' => $first->id,
                'schedule_locked' => true,
            ])->save();
        }
    }

    /**
     * Escenarios para probar la edición manual de horarios y el árbitro en la primera liga: 4 árbitros activos más
     * (uno de ellos juega en «Torneo Impar»), 1 inactivo, y «Torneo Cruzado», un segundo torneo en curso con los mismos
     * equipos y partidos ya programados y fijados en las canchas 1 y 2 de la primera semana, para probar conflictos
     * entre torneos. No hace nada si ya existen.
     *
     * @param  list<League>  $leagues
     */
    private function seedSchedulingScenarios(array $leagues): void
    {
        $league = $leagues[0] ?? null;

        if ($league === null || User::query()->where('user_code', 'ARB101')->exists()) {
            return;
        }

        $referees = User::factory(5)
            ->sequence(fn ($sequence) => ['user_code' => sprintf('ARB%03d', 101 + $sequence->index), 'phone' => $this->phone()])
            ->create();

        foreach ($referees as $index => $referee) {
            $roles = $index === 4 ? [RoleEnum::REFEREE->value, RoleEnum::PLAYER->value] : [RoleEnum::REFEREE->value];
            $referee->roles()->sync($roles);

            $link = LeagueReferee::factory();
            $link = $index === 3 ? $link->inactive() : $link;
            $link->create(['league_id' => $league->id, 'user_id' => $referee->id]);
        }

        $this->seedRefereePlayer($league, $referees[4]);
        $this->seedCrossTournament($league);
    }

    /**
     * El árbitro también juega en el primer equipo de «Torneo Impar» (plantilla activa con el dorsal 99).
     */
    private function seedRefereePlayer(League $league, User $referee): void
    {
        $registration = $league->tournaments()->where('name', 'Torneo Impar')->first()?->registrations()->orderBy('id')->first();

        if ($registration === null) {
            return;
        }

        $player = Player::factory()->create(['user_id' => $referee->id]);
        $row = new Roster;
        $row->forceFill([
            'registration_id' => $registration->id,
            'team_id' => $registration->team_id,
            'tournament_id' => $registration->tournament_id,
            'player_id' => $player->id,
            'jersey_number' => 99,
            'status' => RosterStatus::ACTIVE,
            'requested_by_user_id' => $league->admin_user_id,
            'requested_at' => now()->subDays(10),
            'responded_at' => now()->subDays(9),
        ])->save();
    }

    /**
     * Juega todos los partidos de liguilla, ronda por ronda: gana el equipo de inscripción más antigua (en la vuelta
     * también, así que el global lo decide ese equipo sin penales).
     */
    private function playAllPlayoffMatches(Tournament $tournament): void
    {
        $matches = app(MatchServiceInterface::class);
        $strength = $tournament->registrations()->orderBy('id')->pluck('id')->flip();

        for ($pass = 0; $pass < 10; $pass++) {
            $pending = GameMatch::query()
                ->where('tournament_id', $tournament->id)
                ->where('status', '!=', MatchStatus::PLAYED)
                ->whereNotNull('home_registration_id')
                ->whereNotNull('away_registration_id')
                ->orderBy('id')
                ->get();

            if ($pending->isEmpty()) {
                return;
            }

            foreach ($pending as $match) {
                $homeWins = $strength[$match->home_registration_id] < $strength[$match->away_registration_id];
                $matches->recordResult($tournament, $match, $homeWins ? 2 : 0, $homeWins ? 0 : 2);
            }
        }
    }

    /**
     * Usuarios para probar los 403 por rol, con la contraseña «password»: un árbitro activo de la primera liga
     * (arbitro.prueba@example.com) y un jugador con plantilla activa en su primer torneo (jugador.prueba@example.com).
     * No hace nada si ya existen.
     *
     * @param  list<League>  $leagues
     */
    private function seedRoleTestUsers(array $leagues): void
    {
        $league = $leagues[0] ?? null;

        if ($league === null || User::query()->where('email', 'arbitro.prueba@example.com')->exists()) {
            return;
        }

        $referee = User::factory()->create(['email' => 'arbitro.prueba@example.com', 'user_code' => 'ARBPRUEBA', 'phone' => $this->phone()]);
        $referee->roles()->sync([RoleEnum::REFEREE->value]);
        LeagueReferee::factory()->create(['league_id' => $league->id, 'user_id' => $referee->id]);

        $user = User::factory()->create(['email' => 'jugador.prueba@example.com', 'user_code' => 'JUGPRUEBA', 'phone' => $this->phone()]);
        $user->roles()->sync([RoleEnum::PLAYER->value]);

        $registration = $league->tournaments()->where('name', 'Torneo Impar')->first()?->registrations()->orderBy('id')->first();

        if ($registration === null) {
            return;
        }

        $player = Player::factory()->create(['user_id' => $user->id]);
        $row = new Roster;
        $row->forceFill([
            'registration_id' => $registration->id,
            'team_id' => $registration->team_id,
            'tournament_id' => $registration->tournament_id,
            'player_id' => $player->id,
            'jersey_number' => 98,
            'status' => RosterStatus::ACTIVE,
            'requested_by_user_id' => $league->admin_user_id,
            'requested_at' => now()->subDays(10),
            'responded_at' => now()->subDays(9),
        ])->save();
    }

    /**
     * «Torneo Cruzado»: en curso, con los 4 primeros equipos activos de la liga (también juegan en «Torneo Impar») y su
     * calendario de emparejamientos; dos partidos fijados el sábado de la primera semana (9:00 y 10:00, Cancha 1) y dos
     * el domingo (8:00 y 9:00, Cancha 2), en las mismas canchas y fechas que los horarios de «Torneo Impar».
     */
    private function seedCrossTournament(League $league): void
    {
        if ($league->tournaments()->where('name', 'Torneo Cruzado')->exists()) {
            return;
        }

        $tournament = Tournament::factory()->status(TournamentStatus::IN_PROGRESS)->create([
            'league_id' => $league->id,
            'name' => 'Torneo Cruzado',
            'gender' => TournamentGender::MIXED,
            'min_age' => null,
            'max_age' => null,
            'start_date' => now()->subWeek()->toDateString(),
        ]);
        $group = $tournament->groups()->firstOrFail();

        foreach ($league->teams()->where('status', TeamStatus::ACTIVE)->orderBy('id')->take(4)->get() as $team) {
            Registration::factory()->create(['tournament_id' => $tournament->id, 'team_id' => $team->id, 'group_id' => $group->id]);
        }

        app(MatchServiceInterface::class)->generate($tournament, false);

        $fields = $league->fields()->where('status', FieldStatus::ACTIVE)->orderBy('id')->take(2)->get();
        $monday = now()->next(CarbonInterface::MONDAY)->startOfDay()->toImmutable();
        $slots = [[5, 9, 0], [5, 10, 0], [6, 8, 1], [6, 9, 1]];

        foreach ($tournament->matches()->orderBy('matchday')->orderBy('id')->take(4)->get()->values() as $index => $match) {
            [$offset, $hour, $fieldIndex] = $slots[$index];
            $field = $fields[$fieldIndex] ?? null;

            if ($field === null) {
                continue;
            }

            $match->forceFill([
                'match_datetime' => $monday->addDays($offset)->setTime($hour, 0)->toDateTimeString(),
                'field_id' => $field->id,
                'schedule_locked' => true,
            ])->save();
        }
    }

    /**
     * Guarda la configuración de horarios de un torneo con las canchas activas de la liga por orden (Cancha 1, 2, 3).
     *
     * @param  list<array{0: int, 1: int, 2: string, 3: string}>  $windows  día de la semana (1 = lunes), índice de cancha, inicio y fin
     */
    private function seedScheduleWindows(Tournament $tournament, League $league, string $startDate, array $windows): void
    {
        $fields = $league->fields()->where('status', FieldStatus::ACTIVE)->orderBy('id')->get();
        $rows = [];

        foreach ($windows as [$weekday, $fieldIndex, $start, $end]) {
            $field = $fields[$fieldIndex] ?? null;

            if ($field !== null) {
                $rows[] = ['weekday' => $weekday, 'field_id' => $field->id, 'start_time' => $start, 'end_time' => $end];
            }
        }

        app(ScheduleRepositoryInterface::class)->replace($tournament, $startDate, $rows, []);
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
            'team_id' => $registration->team_id,
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
                        'team_id' => $registration->team_id,
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
                'team_id' => $registration->team_id,
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

    /**
     * Ligas demo («Liga Demo 1», «Liga Demo 2», …): cada una con su admin, de 2 a 4 canchas, árbitros activos (una cancha
     * más que canchas, para cubrir partidos simultáneos) y equipos propios, y con 2 o 3 de los torneos de ejemplo
     * completos. Nada se comparte con otras ligas, y los torneos de una misma liga usan días de la semana distintos
     * (WINDOW_PATTERNS) para no chocar en canchas, árbitros ni equipos. Los torneos en planeación quedan sin calendario,
     * sin horarios y sin árbitros a propósito. Una liga que ya existe se omite, así que es seguro correrlo varias veces.
     */
    private function seedDemoLeagues(): void
    {
        $managerIds = User::query()
            ->whereHas('roles', fn ($q) => $q->where('roles.id', RoleEnum::MANAGER->value))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        foreach (self::DEMO_LEAGUES as $number => $spec) {
            $name = self::DEMO_LEAGUE_PREFIX.$number;

            if (League::query()->where('name', $name)->exists()) {
                continue;
            }

            DB::transaction(function () use ($number, $name, $spec, $managerIds): void {
                $admin = User::factory()->create(['user_code' => sprintf('LIGAD%02d', $number), 'phone' => $this->phone()]);
                $admin->roles()->sync([RoleEnum::LEAGUE_ADMIN->value]);
                $league = League::factory()->create(['admin_user_id' => $admin->id, 'name' => $name]);

                for ($field = 1; $field <= $spec['fields']; $field++) {
                    Field::factory()->create(['league_id' => $league->id, 'name' => "Cancha {$field}"]);
                }

                for ($i = 1; $i <= $spec['fields'] + 1; $i++) {
                    $referee = User::factory()->create(['user_code' => sprintf('ARBD%d%02d', $number, $i), 'phone' => $this->phone()]);
                    $referee->roles()->sync([RoleEnum::REFEREE->value]);
                    LeagueReferee::factory()->create(['league_id' => $league->id, 'user_id' => $referee->id]);
                }

                $teams = collect();

                for ($i = 0; $i < $spec['teams']; $i++) {
                    $teams->push(Team::factory()->create([
                        'league_id' => $league->id,
                        'name' => self::TEAM_NAMES[$i] ?? 'Equipo Extra '.($i + 1),
                        'manager_user_id' => $managerIds === [] ? null : $managerIds[($number * 7 + $i) % count($managerIds)],
                    ]));
                }

                foreach ($spec['tournaments'] as $slot => $scenario) {
                    $this->seedDemoTournament($league, $scenario, $slot, $teams);
                }
            });
        }
    }

    /**
     * Crea un torneo de ejemplo en la liga demo. `$slot` (0 a 2) elige el patrón de ventanas semanales del torneo.
     *
     * @param  Collection<int, Team>  $teams
     */
    private function seedDemoTournament(League $league, string $scenario, int $slot, Collection $teams): void
    {
        $make = fn (string $name, TournamentStatus $status, array $extra = []): Tournament => Tournament::factory()->status($status)->create([
            'league_id' => $league->id,
            'name' => $name,
            'gender' => TournamentGender::MIXED,
            'min_age' => null,
            'max_age' => null,
            'rounds' => 1,
            'start_date' => now()->toDateString(),
        ] + $extra);
        $register = function (Tournament $tournament, int $count, ?TournamentGroup $group = null) use ($teams): void {
            foreach ($teams->take($count) as $team) {
                Registration::factory()->create(['tournament_id' => $tournament->id, 'team_id' => $team->id, 'group_id' => $group?->id]);
            }
        };
        $single = function (string $name, int $count, array $extra = []) use ($make, $register): array {
            $tournament = $make($name, TournamentStatus::IN_PROGRESS, $extra);
            $group = $tournament->groups()->firstOrFail();
            $register($tournament, $count, $group);

            return [$tournament, $group];
        };
        $played = fn (int $matchdays): int => $matchdays;

        switch ($scenario) {
            case 'groups':
                $tournament = $make('Torneo de Grupos', TournamentStatus::PLANNING, ['rounds' => 1, 'start_date' => now()->addMonths(2)->toDateString()]);
                $register($tournament, 8);

                break;

            case 'regular':
                // Calendario completo y horarios en el futuro, sin partidos jugados.
                [$tournament, $group] = $single('Torneo en Fase Regular', 4);
                $this->seedScheduledCalendar($tournament, $league, $slot, fn (): int => -1);
                $this->seedPlayoff($tournament, 'Liguilla', [[$group, 1, 4]], null);

                break;

            case 'playoffs':
                // Fase regular jugada y cerrada; liguilla [2, 1]: semifinal 1 definida por penales, semifinal 2 a medias.
                [$tournament, $group] = $single('Torneo en Liguilla', 4);
                $this->seedScheduledCalendar($tournament, $league, $slot, $played);
                $this->playRegularMatches($tournament);
                $playoff = $this->seedPlayoff($tournament, 'Liguilla', [[$group, 1, 4]], [2, 1]);
                $this->closeAndSchedulePlayoffs($tournament, $league, $slot, [$playoff], 3, false);
                $this->seedSemifinalResults($tournament->refresh(), $playoff);

                break;

            case 'four_groups':
                $tournament = $make('Torneo 4 Grupos', TournamentStatus::IN_PROGRESS);
                $groups = $this->seedGroups($tournament, $teams, ['Grupo A', 'Grupo B', 'Grupo C', 'Grupo D'], 4);
                $this->seedScheduledCalendar($tournament, $league, $slot, $played);
                $this->playRegularMatches($tournament);
                $principal = $this->seedPlayoff($tournament, 'Liguilla Principal', array_map(fn (TournamentGroup $group) => [$group, 1, 2], $groups), [1, 2, 1]);
                $consolation = $this->seedPlayoff($tournament, 'Liguilla Consolación', array_map(fn (TournamentGroup $group) => [$group, 3, 4], $groups), [1, 2, 1]);
                $this->closeAndSchedulePlayoffs($tournament, $league, $slot, [$principal, $consolation], 4, false);

                break;

            case 'to_close':
                [$tournament, $group] = $single('Torneo por Cerrar', 8);
                $this->seedScheduledCalendar($tournament, $league, $slot, $played);
                $this->playRegularMatches($tournament);
                $playoff = $this->seedPlayoff($tournament, 'Liguilla', [[$group, 1, 8]], [1, 2, 1]);
                $dates = $this->playoffDates($tournament, $slot, 4, false);
                $playoff->forceFill(['start_date' => $dates[0], 'round_dates' => $dates])->save();

                break;

            case 'tied':
                // El primero gana 1-0 a todos; el 2.º y el 3.º ganan 1-0 al 4.º y empatan 0-0 entre sí.
                [$tournament, $group] = $single('Torneo con Empate', 4);
                $this->seedScheduledCalendar($tournament, $league, $slot, $played);
                $this->playRegularMatches($tournament, function (int $home, int $away): array {
                    $stronger = min($home, $away);

                    if ($stronger === 0 || ($stronger !== 3 && max($home, $away) === 3)) {
                        return $home < $away ? [1, 0] : [0, 1];
                    }

                    return [0, 0];
                });
                $this->seedPlayoff($tournament, 'Liguilla', [[$group, 1, 2]], null);

                break;

            case 'finished':
                [$tournament, $group] = $single('Torneo Finalizado', 4);
                $this->seedScheduledCalendar($tournament, $league, $slot, fn (int $matchdays): int => $matchdays + 5);
                $this->playRegularMatches($tournament);
                $playoff = $this->seedPlayoff($tournament, 'Liguilla', [[$group, 1, 4]], [2, 1]);
                $this->closeAndSchedulePlayoffs($tournament, $league, $slot, [$playoff], 3, true);
                $this->playAllPlayoffMatches($tournament->refresh());
                $tournament->forceFill(['status' => TournamentStatus::FINISHED])->save();

                break;

            case 'two_playoffs':
                [$tournament, $group] = $single('Torneo 2 Liguillas', 8);
                $this->seedScheduledCalendar($tournament, $league, $slot, $played);
                $this->playRegularMatches($tournament);
                $principal = $this->seedPlayoff($tournament, 'Liguilla Principal', [[$group, 1, 4]], [1, 1]);
                $consolation = $this->seedPlayoff($tournament, 'Liguilla Consolación', [[$group, 5, 8]], [1, 1]);
                $this->closeAndSchedulePlayoffs($tournament, $league, $slot, [$principal, $consolation], 2, false);

                break;

            case 'withdrawal':
                // La primera jornada (la semana pasada) jugada, un partido por default y el último equipo dado de baja.
                [$tournament, $group] = $single('Torneo con Baja', 4);
                $this->seedScheduledCalendar($tournament, $league, $slot, fn (): int => 1);
                $this->seedWithdrawal($tournament, $league, $group);

                break;

            case 'minimums':
                // Sin calendario (generarlo se rechaza por los partidos mínimos), pero con horarios guardados.
                [$tournament] = $single('Torneo Mínimos', 4, ['min_matches_for_playoffs' => 6]);
                $this->seedScheduleWindows($tournament, $league, now()->next(CarbonInterface::MONDAY)->toDateString(), $this->windowRows($slot, $league));

                break;

            default:
                // t25_planning, t25_to_close, t25_tied: 5 grupos de 5 equipos y liguilla de las posiciones 1 a 3 + mejor 4.º.
                $planning = $scenario === 't25_planning';
                $name = ['t25_planning' => 'Torneo 25 Equipos', 't25_to_close' => 'Torneo 25 por Cerrar', 't25_tied' => 'Torneo 25 con Empate'][$scenario];
                $tournament = $make($name, $planning ? TournamentStatus::PLANNING : TournamentStatus::IN_PROGRESS, $planning ? ['start_date' => now()->addMonths(3)->toDateString()] : []);
                $groups = $this->seedGroups($tournament, $teams, ['Grupo A', 'Grupo B', 'Grupo C', 'Grupo D', 'Grupo E'], 5);
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

                if ($planning) {
                    break;
                }

                $this->seedScheduledCalendar($tournament, $league, $slot, $played);

                // «Por cerrar»: un mejor 4.º claro (el del último grupo gana su partido con más goles); «con empate»: todos los 4.º empatan.
                $this->playRegularMatches($tournament, $scenario === 't25_to_close' ? function (int $home, int $away): array {
                    $lower = min($home, $away);
                    $margin = ($lower % 5 === 3 && max($home, $away) % 5 === 4) ? intdiv($lower, 5) + 2 : 2;

                    return $home < $away ? [$margin, 0] : [0, $margin];
                } : null);
        }

    }

    /**
     * Quita el grupo automático y crea los grupos indicados con `$size` equipos cada uno (los primeros de `$teams`).
     *
     * @param  Collection<int, Team>  $teams
     * @param  list<string>  $names
     * @return list<TournamentGroup>
     */
    private function seedGroups(Tournament $tournament, Collection $teams, array $names, int $size): array
    {
        $tournament->groups()->where('name', TournamentGroup::DEFAULT_NAME)->delete();
        $groups = [];

        foreach ($names as $index => $name) {
            $group = new TournamentGroup;
            $group->forceFill(['tournament_id' => $tournament->id, 'name' => $name, 'type' => TournamentGroupType::REGULAR_STAGE])->save();
            $groups[] = $group;

            foreach ($teams->slice($index * $size, $size) as $team) {
                Registration::factory()->create(['tournament_id' => $tournament->id, 'team_id' => $team->id, 'group_id' => $group->id]);
            }
        }

        return $groups;
    }

    /**
     * Filas de ventanas del patrón `$slot` en todas las canchas activas de la liga.
     *
     * @return list<array{0: int, 1: int, 2: string, 3: string}>
     */
    private function windowRows(int $slot, League $league): array
    {
        $count = $league->fields()->where('status', FieldStatus::ACTIVE)->count();
        $rows = [];

        foreach (self::WINDOW_PATTERNS[$slot] as [$weekday, $start, $end]) {
            for ($field = 0; $field < $count; $field++) {
                $rows[] = [$weekday, $field, $start, $end];
            }
        }

        return $rows;
    }

    /**
     * Genera el calendario de emparejamientos, guarda las ventanas del torneo, asigna fecha, hora y cancha con el
     * generador del sistema (que respeta lo ocupado por los torneos anteriores de la liga) y reparte árbitros.
     * `$weeksBack` recibe el número de jornadas y devuelve cuántas semanas antes del lunes de esta semana empieza el
     * calendario (negativo = en el futuro). Falla si algún partido queda sin horario.
     *
     * @param  callable(int): int  $weeksBack
     */
    private function seedScheduledCalendar(Tournament $tournament, League $league, int $slot, callable $weeksBack): void
    {
        app(MatchServiceInterface::class)->generate($tournament, false);

        $matchdays = (int) GameMatch::query()->where('tournament_id', $tournament->id)->max('matchday');
        $monday = now()->startOfWeek(CarbonInterface::MONDAY)->toImmutable()->subWeeks($weeksBack($matchdays));

        $this->seedScheduleWindows($tournament, $league, $monday->toDateString(), $this->windowRows($slot, $league));
        $tournament->forceFill(['start_date' => $monday->lt(now()) ? $monday->toDateString() : now()->toDateString()])->save();

        $result = app(ScheduleGeneratorServiceInterface::class)->generate(
            $tournament->refresh(),
            ScheduleGeneratorServiceInterface::MODE_BALANCED,
            ScheduleGeneratorServiceInterface::SCOPE_ALL,
            null,
        );

        if ($result['unscheduled'] !== 0) {
            throw new RuntimeException("«{$tournament->name}» quedó con {$result['unscheduled']} partidos sin horario: amplía sus ventanas.");
        }

        $this->assignReferees($league, $tournament);
    }

    /**
     * Marca como jugados todos los partidos del torneo (por defecto gana el de inscripción más antigua, 2-0).
     *
     * @param  (callable(int, int): array{0: int, 1: int})|null  $score  Orden de inscripción del local y del visitante → goles.
     */
    private function playRegularMatches(Tournament $tournament, ?callable $score = null): void
    {
        $strength = $tournament->registrations()->orderBy('id')->pluck('id')->flip();
        $score ??= fn (int $home, int $away) => $home < $away ? [2, 0] : [0, 2];

        foreach (GameMatch::query()->where('tournament_id', $tournament->id)->get() as $match) {
            [$homeGoals, $awayGoals] = $score($strength[$match->home_registration_id], $strength[$match->away_registration_id]);

            $match->forceFill(['status' => MatchStatus::PLAYED, 'home_score' => $homeGoals, 'away_score' => $awayGoals])->save();
        }
    }

    /**
     * Fechas semanales de las rondas de liguilla, en el día principal del patrón `$slot`, después del último partido
     * de fase regular (y, si no es `$past`, a partir de mañana).
     *
     * @return list<string>
     */
    private function playoffDates(Tournament $tournament, int $slot, int $count, bool $past): array
    {
        $last = CarbonImmutable::parse((string) GameMatch::query()->where('tournament_id', $tournament->id)->max('match_datetime'))->startOfDay();
        $from = $last->addDay();
        $tomorrow = now()->addDay()->startOfDay()->toImmutable();

        if (! $past && $from->lt($tomorrow)) {
            $from = $tomorrow;
        }

        while ($from->dayOfWeekIso !== self::WINDOW_PATTERNS[$slot][0][0]) {
            $from = $from->addDay();
        }

        return array_map(fn (int $week): string => $from->addWeeks($week)->toDateString(), range(0, $count - 1));
    }

    /**
     * Cierra la fase regular con las fechas por ronda, asigna hora y cancha a la liguilla con PlayoffScheduleService y
     * reparte árbitros.
     *
     * @param  list<TournamentGroup>  $playoffs
     */
    private function closeAndSchedulePlayoffs(Tournament $tournament, League $league, int $slot, array $playoffs, int $dates, bool $past): void
    {
        $roundDates = $this->playoffDates($tournament, $slot, $dates, $past);

        app(PlayoffServiceInterface::class)->close($tournament, array_map(
            fn (TournamentGroup $playoff): array => ['group_id' => $playoff->id, 'round_dates' => $roundDates],
            $playoffs,
        ));

        $result = app(PlayoffScheduleServiceInterface::class)->generate($tournament->refresh(), 'all', false);

        if ($result['unscheduled'] !== 0) {
            throw new RuntimeException("La liguilla de «{$tournament->name}» quedó con {$result['unscheduled']} partidos sin horario: amplía sus ventanas.");
        }

        $this->assignReferees($league, $tournament);
    }

    /**
     * Árbitro para cada partido del torneo con horario y sin árbitro: el activo de la liga con menos partidos que no
     * tenga otro partido de la liga que se traslape (de cualquier torneo).
     */
    private function assignReferees(League $league, Tournament $tournament): void
    {
        $referees = LeagueReferee::query()
            ->where('league_id', $league->id)
            ->where('status', LeagueRefereeStatus::ACTIVE)
            ->orderBy('user_id')
            ->pluck('user_id')
            ->all();
        $durations = Tournament::query()->where('league_id', $league->id)->pluck('match_duration_minutes', 'id');
        $load = array_fill_keys($referees, 0);
        $busy = [];

        $assigned = GameMatch::query()->whereIn('tournament_id', $durations->keys())->whereNotNull('referee_user_id')->whereNotNull('match_datetime')->get();

        foreach ($assigned as $match) {
            $start = $match->match_datetime->getTimestamp();
            $busy[$match->referee_user_id][] = [$start, $start + $durations[$match->tournament_id] * 60];
            $load[$match->referee_user_id] = ($load[$match->referee_user_id] ?? 0) + 1;
        }

        $pending = GameMatch::query()->where('tournament_id', $tournament->id)->whereNotNull('match_datetime')->whereNull('referee_user_id')
            ->orderBy('match_datetime')->orderBy('id')->get();

        foreach ($pending as $match) {
            $start = $match->match_datetime->getTimestamp();
            $end = $start + $durations[$match->tournament_id] * 60;
            $candidates = $referees;
            usort($candidates, fn (int $a, int $b): int => [$load[$a], $a] <=> [$load[$b], $b]);

            $referee = null;

            foreach ($candidates as $candidate) {
                $overlaps = false;

                foreach ($busy[$candidate] ?? [] as [$from, $to]) {
                    if ($start < $to && $from < $end) {
                        $overlaps = true;

                        break;
                    }
                }

                if (! $overlaps) {
                    $referee = $candidate;

                    break;
                }
            }

            if ($referee === null) {
                throw new RuntimeException("No hay árbitro libre para un partido de «{$tournament->name}» en {$league->name}.");
            }

            $match->forceFill(['referee_user_id' => $referee])->save();
            $busy[$referee][] = [$start, $end];
            $load[$referee]++;
        }
    }

    /**
     * Jornada 1 jugada, un partido de la 2 ganado por default porque un equipo no se presentó y el último equipo dado
     * de baja: uno de sus partidos pendientes se resuelve por default y los demás siguen pendientes (con horario y árbitro).
     */
    private function seedWithdrawal(Tournament $tournament, League $league, TournamentGroup $group): void
    {
        $matchService = app(MatchServiceInterface::class);
        $withdrawn = $tournament->registrations()->orderBy('id')->get()->last();
        $matches = GameMatch::query()->where('tournament_id', $tournament->id)->orderBy('matchday')->orderBy('id')->get();

        foreach ($matches->where('matchday', 1) as $match) {
            $matchService->recordResult($tournament, $match, 2, 1);
        }

        $noShow = $matches->where('matchday', 2)->first(fn (GameMatch $match) => $withdrawn instanceof Registration
            && $match->home_registration_id !== $withdrawn->id && $match->away_registration_id !== $withdrawn->id);

        if ($noShow instanceof GameMatch) {
            $matchService->registerWalkover($tournament, $noShow, (int) $noShow->home_registration_id, 'No se presentó a la hora del partido.', $league->admin_user_id);
        }

        if (! $withdrawn instanceof Registration) {
            return;
        }

        app(RegistrationChangeServiceInterface::class)->withdraw($tournament->refresh(), $withdrawn, 'El equipo abandonó el torneo.', (int) $league->admin_user_id);

        $pending = GameMatch::query()->where('tournament_id', $tournament->id)->where('status', MatchStatus::SCHEDULED)
            ->where(fn ($query) => $query->where('home_registration_id', $withdrawn->id)->orWhere('away_registration_id', $withdrawn->id))
            ->orderBy('matchday')->first();

        if ($pending instanceof GameMatch) {
            $matchService->registerWalkover($tournament->refresh(), $pending, $withdrawn->id, 'Equipo dado de baja.', (int) $league->admin_user_id);
        }
    }
}
