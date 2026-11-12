<?php

namespace Tests\Feature\Schedule;

use App\Contracts\Repositories\MatchRepositoryInterface;
use App\Core\Enum\MatchStatus;
use App\Core\Enum\TournamentStatus;
use App\Models\Field;
use App\Models\GameMatch;
use App\Models\League;
use App\Models\Registration;
use App\Models\Tournament;
use App\Models\TournamentScheduleWindow;
use App\Services\FieldAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Groups\InteractsWithGroups;
use Tests\Support\RacingMatchRepository;
use Tests\TestCase;

/**
 * Canchas compartidas entre torneos de una liga: capacidad, vista previa, asignación, programación a mano y
 * configuraciones coincidentes usan la misma regla de disponibilidad.
 *
 * «Torneo Dos» (en curso, 12 equipos: 11 jornadas de 6 partidos de 60 min) juega los domingos de 8:00 a 11:00 en
 * Cancha 1 y Cancha 2 (6 espacios por jornada) desde el lunes 2026-11-02. «Torneo Uno» es otro torneo de la liga.
 */
class SharedFieldAvailabilityTest extends TestCase
{
    use InteractsWithGroups, RefreshDatabase;

    /** Domingos de las jornadas 1 a 5 de Torneo Dos. */
    private const SUNDAYS = ['2026-11-08', '2026-11-15', '2026-11-22', '2026-11-29', '2026-12-06'];

    private League $league;

    private Tournament $one;

    private Tournament $two;

    /** @var array<string, string> */
    private array $header;

    /** @var list<Field> */
    private array $fields;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->league, $this->two, $this->header] = $this->tournamentWithAdminHeader();
        $this->two->forceFill(['name' => 'Torneo Dos', 'status' => TournamentStatus::IN_PROGRESS])->save();
        $this->teamsIn($this->two, $this->two->groups()->firstOrFail(), 12);
        $this->postJson($this->api($this->two, 'matches/generate'), ['shuffle' => false], $this->header)->assertOk();

        $this->one = Tournament::factory()->status(TournamentStatus::IN_PROGRESS)
            ->create(['league_id' => $this->league->id, 'name' => 'Torneo Uno', 'match_duration_minutes' => 120]);

        $this->fields = [
            Field::factory()->create(['league_id' => $this->league->id, 'name' => 'Cancha 1']),
            Field::factory()->create(['league_id' => $this->league->id, 'name' => 'Cancha 2']),
        ];

        $this->putJson($this->api($this->two, 'schedule-config'), $this->config(), $this->header)->assertOk();
    }

    protected function tearDown(): void
    {
        RacingMatchRepository::$beforeLockedRead = null;

        parent::tearDown();
    }

    private function api(Tournament $tournament, string $path): string
    {
        return "/api/leagues/{$this->league->id}/tournaments/{$tournament->id}/{$path}";
    }

    /**
     * @param  list<array<string, mixed>>|null  $windows
     * @return array<string, mixed>
     */
    private function config(?array $windows = null): array
    {
        return [
            'schedule_start_date' => '2026-11-02',
            'windows' => $windows ?? array_map(fn (Field $field) => $this->window($field, '08:00', '11:00'), $this->fields),
            'blocks' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function window(Field $field, string $start, string $end): array
    {
        return ['weekday' => 7, 'field_id' => $field->id, 'start_time' => $start, 'end_time' => $end];
    }

    /**
     * Partido de otro torneo de la liga (con equipos propios) en esa cancha y hora.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function occupy(Tournament $tournament, Field $field, string $at, array $attributes = []): GameMatch
    {
        $group = $tournament->groups()->firstOrFail();
        $teams = $this->teamsIn($tournament, $group, 2);
        $match = new GameMatch;
        $match->forceFill($attributes + [
            'tournament_id' => $tournament->id, 'group_id' => $group->id,
            'home_registration_id' => $teams[0]->id, 'away_registration_id' => $teams[1]->id,
            'phase' => 'regular_stage', 'matchday' => 1, 'round_number' => 1, 'status' => MatchStatus::SCHEDULED,
            'match_datetime' => $at, 'field_id' => $field->id,
        ])->save();

        return $match;
    }

    /**
     * Torneo Uno ocupa Cancha 1 de 8:00 a 10:00 (un partido de 120 min) los domingos de las jornadas 1 a 5.
     */
    private function torneoUnoTakesFieldOne(): void
    {
        foreach (self::SUNDAYS as $date) {
            $this->occupy($this->one, $this->fields[0], "{$date} 08:00:00");
        }
    }

    /**
     * @return array<int, int> Jornada => déficit de la capacidad.
     */
    private function deficits(): array
    {
        $matchdays = $this->getJson($this->api($this->two, 'schedule-config/capacity'), $this->header)->assertOk()->json('matchdays');

        return array_column($matchdays, 'deficit', 'matchday');
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<int, int> Jornada => partidos sin horario de la vista previa.
     */
    private function unscheduled(array $body = []): array
    {
        $matchdays = $this->postJson($this->api($this->two, 'schedule/preview'), $body, $this->header)->assertOk()->json('matchdays');

        return array_column($matchdays, 'unscheduled', 'matchday');
    }

    /**
     * Ningún par de partidos no cancelados de la liga se traslapa en la misma cancha.
     */
    private function assertNoFieldOverlaps(): void
    {
        $matches = GameMatch::query()
            ->whereHas('tournament', fn ($query) => $query->where('league_id', $this->league->id))
            ->whereNotNull('match_datetime')->whereNotNull('field_id')
            ->where('status', '!=', MatchStatus::CANCELLED)
            ->with('tournament')->orderBy('field_id')->orderBy('match_datetime')->get()->all();

        $this->assertNotEmpty($matches);

        foreach ($matches as $i => $a) {
            foreach (array_slice($matches, $i + 1) as $b) {
                [$fromA, $toA] = FieldAvailability::interval($a) ?? [0, 0];
                [$fromB, $toB] = FieldAvailability::interval($b) ?? [0, 0];

                $this->assertFalse(
                    $a->field_id === $b->field_id && $fromA < $toB && $fromB < $toA,
                    "Los partidos {$a->id} y {$b->id} se traslapan en la cancha {$a->field_id} ({$a->match_datetime} / {$b->match_datetime})."
                );
            }
        }
    }

    public function test_capacity_discounts_the_slots_taken_by_other_tournaments_like_the_preview(): void
    {
        $this->torneoUnoTakesFieldOne();

        $capacity = $this->getJson($this->api($this->two, 'schedule-config/capacity'), $this->header)->assertOk();
        $matchdays = $capacity->json('matchdays');
        $this->assertCount(11, $matchdays);

        foreach ($matchdays as $index => $matchday) {
            $taken = $index < 5;

            $this->assertSame(6, $matchday['matches_required']);
            $this->assertSame(6, $matchday['slots_configured']);
            $this->assertSame($taken ? 2 : 0, $matchday['slots_occupied'], "jornada {$matchday['matchday']}");
            $this->assertSame($taken ? 4 : 6, $matchday['slots_available']);
            $this->assertSame($taken ? 2 : 0, $matchday['deficit']);
            $this->assertSame($taken ? [[
                'date' => self::SUNDAYS[$index],
                'field_id' => $this->fields[0]->id,
                'field_name' => 'Cancha 1',
                'start_time' => '08:00',
                'end_time' => '10:00',
                'tournament_id' => $this->one->id,
                'tournament_name' => 'Torneo Uno',
            ]] : [], $matchday['occupied_by_others']);
        }

        $capacity->assertJsonCount(5, 'warnings')->assertJsonMissingPath('config_overlaps');

        $preview = $this->postJson($this->api($this->two, 'schedule/preview'), [], $this->header)
            ->assertOk()->assertJsonPath('scheduled', 56)->assertJsonPath('unscheduled', 10)
            ->assertJsonPath('warnings.0.message', 'Faltan 2 espacios en la jornada 1.');

        foreach ($preview->json('matchdays') as $index => $matchday) {
            $this->assertSame($matchdays[$index]['deficit'], $matchday['unscheduled']);
            $this->assertSame($matchdays[$index]['occupied_by_others'], $matchday['occupied_by_others']);
        }
    }

    /**
     * @return array<string, array{0: int, 1: list<array{0: int, 1: string, 2?: array<string, mixed>}>, 2: int}>
     *                                                                                                           Duración de Torneo Uno, sus partidos [cancha, hora, atributos] y déficit esperado de la jornada 1.
     */
    public static function occupations(): array
    {
        return [
            'sin partidos de otros torneos' => [60, [], 0],
            'dos horas en una cancha' => [120, [[0, '2026-11-08 08:00:00']], 2],
            'traslape parcial de 90 minutos' => [90, [[1, '2026-11-08 08:30:00']], 2],
            'pegado antes y después de la ventana' => [60, [[0, '2026-11-08 07:00:00'], [1, '2026-11-08 11:00:00']], 0],
            'otro día de la semana' => [60, [[0, '2026-11-07 08:00:00']], 0],
            'jugado ocupa y cancelado no' => [60, [[0, '2026-11-08 08:00:00', ['status' => MatchStatus::PLAYED, 'home_score' => 1, 'away_score' => 0]], [1, '2026-11-08 08:00:00', ['status' => MatchStatus::CANCELLED]]], 1],
            'fijados en las dos canchas' => [60, [[0, '2026-11-08 09:00:00', ['schedule_locked' => true]], [1, '2026-11-08 10:00:00', ['schedule_locked' => true]]], 2],
            'una cancha completa' => [180, [[1, '2026-11-08 08:00:00']], 3],
        ];
    }

    /**
     * @param  list<array{0: int, 1: string, 2?: array<string, mixed>}>  $matches
     */
    #[DataProvider('occupations')]
    public function test_capacity_deficit_equals_the_unscheduled_of_the_preview(int $duration, array $matches, int $expected): void
    {
        $this->one->forceFill(['match_duration_minutes' => $duration])->save();

        foreach ($matches as $match) {
            $this->occupy($this->one, $this->fields[$match[0]], $match[1], $match[2] ?? []);
        }

        $deficits = $this->deficits();

        $this->assertSame($expected, $deficits[1]);
        $this->assertSame(0, $deficits[2]);
        $this->assertSame($deficits, $this->unscheduled());
        $this->assertSame($deficits, $this->unscheduled(['mode' => 'random', 'seed' => 7]));
    }

    public function test_no_field_overlaps_after_generate_update_and_swap(): void
    {
        $this->torneoUnoTakesFieldOne();
        $schedule = fn (GameMatch $match, array $body) => $this->patchJson("/api/leagues/{$this->league->id}/matches/{$match->id}/schedule", $body, $this->header);

        $this->postJson($this->api($this->two, 'schedule/generate'), [], $this->header)->assertOk()->assertJsonPath('unscheduled', 10);
        $this->assertNoFieldOverlaps();

        // A mano: el espacio de Torneo Uno se rechaza; uno libre fuera de las ventanas se acepta.
        $pending = GameMatch::query()->where('tournament_id', $this->two->id)->where('matchday', 1)->whereNull('match_datetime')->firstOrFail();
        $schedule($pending, ['match_datetime' => '2026-11-08 09:00:00', 'field_id' => $this->fields[0]->id])
            ->assertUnprocessable()->assertJsonPath('error.code', 'field_conflict');
        $schedule($pending, ['match_datetime' => '2026-11-08 11:00:00', 'field_id' => $this->fields[0]->id])->assertOk();
        $this->assertNoFieldOverlaps();

        // Intercambio entre dos partidos con horario y con uno que no lo tiene.
        [$first, $second] = GameMatch::query()->where('tournament_id', $this->two->id)->where('matchday', 2)->whereNotNull('match_datetime')->orderBy('id')->get()->all();
        $unscheduled = GameMatch::query()->where('tournament_id', $this->two->id)->where('matchday', 2)->whereNull('match_datetime')->firstOrFail();

        foreach ([$second, $unscheduled] as $with) {
            $this->postJson("/api/leagues/{$this->league->id}/matches/{$first->id}/swap-schedule", ['with_match_id' => $with->id], $this->header)->assertOk();
            $this->assertNoFieldOverlaps();
        }

        // Volver a asignar respeta lo fijado a mano y lo de Torneo Uno.
        $this->postJson($this->api($this->two, 'schedule/generate'), ['mode' => 'random', 'seed' => 3], $this->header)->assertOk();
        $this->assertNoFieldOverlaps();
    }

    public function test_generate_skips_a_slot_taken_between_the_computation_and_the_save(): void
    {
        // Con 60 min el partido que se cuela ocupa exactamente un espacio de Torneo Dos.
        $this->one->forceFill(['match_duration_minutes' => 60])->save();
        $preview = $this->postJson($this->api($this->two, 'schedule/preview'), [], $this->header)->assertOk()->assertJsonPath('scheduled', 66);
        $victim = $preview->json('matches.0');
        $at = str_replace('T', ' ', substr((string) $victim['match_datetime'], 0, 19));
        $field = Field::query()->findOrFail($victim['field']['id']);

        // Otro usuario programa un partido de Torneo Uno en ese espacio justo antes de que se guarde la asignación.
        $this->app->bind(MatchRepositoryInterface::class, RacingMatchRepository::class);
        RacingMatchRepository::$beforeLockedRead = fn () => $this->occupy($this->one, $field, $at);

        $response = $this->postJson($this->api($this->two, 'schedule/generate'), [], $this->header)
            ->assertOk()
            ->assertJsonPath('scheduled', 65)
            ->assertJsonPath('unscheduled', 1)
            ->assertJsonPath('matchdays.0.scheduled', 5)
            ->assertJsonPath('matchdays.0.unscheduled', 1)
            ->assertJsonPath('matches.0.id', $victim['id'])
            ->assertJsonPath('matches.0.match_datetime', null)
            ->assertJsonPath('matches.0.field', null)
            ->assertJsonCount(1, 'warnings')
            ->assertJsonPath('warnings.0.code', 'slot_taken')
            ->assertJsonPath('warnings.0.match.id', $victim['id'])
            ->assertJsonPath('warnings.0.occupied_by.tournament_id', $this->one->id)
            ->assertJsonPath('warnings.0.occupied_by.tournament_name', 'Torneo Uno')
            ->assertJsonPath('warnings.0.occupied_by.field_id', $field->id);

        $this->assertStringContainsString('Torneo Uno', (string) $response->json('warnings.0.message'));
        $this->assertNull(RacingMatchRepository::$beforeLockedRead);

        $saved = GameMatch::query()->findOrFail($victim['id']);
        $this->assertNull($saved->match_datetime);
        $this->assertNull($saved->field_id);
        $this->assertSame(65, GameMatch::query()->where('tournament_id', $this->two->id)->whereNotNull('match_datetime')->count());
        $this->assertSame(1, GameMatch::query()->where('tournament_id', $this->one->id)->count());
        $this->assertNoFieldOverlaps();
    }

    public function test_matching_configs_of_other_tournaments_are_reported_without_blocking_the_save(): void
    {
        [$fieldOne, $fieldTwo] = $this->fields;

        // Torneo Uno (en curso): domingos, Cancha 1 de 8:00 a 10:00 y Cancha 2 de 12:00 a 14:00.
        $this->putJson($this->api($this->one, 'schedule-config'), [
            'schedule_start_date' => '2027-01-04',
            'windows' => [$this->window($fieldOne, '08:00', '10:00'), $this->window($fieldTwo, '12:00', '14:00')],
            'blocks' => [],
        ], $this->header)->assertOk();

        // Un torneo terminado con la misma ventana no cuenta.
        $finished = Tournament::factory()->status(TournamentStatus::FINISHED)->create(['league_id' => $this->league->id]);
        (new TournamentScheduleWindow)->forceFill(['tournament_id' => $finished->id, 'weekday' => 7, 'field_id' => $fieldOne->id, 'start_time' => '08:00', 'end_time' => '12:00'])->save();

        // Coincide solo Cancha 1 (de 9:00 a 10:00): en Cancha 2 los horarios están pegados y el sábado es otro día.
        $config = $this->config([
            $this->window($fieldOne, '09:00', '12:00'),
            $this->window($fieldTwo, '08:00', '12:00'),
            ['weekday' => 6] + $this->window($fieldOne, '08:00', '10:00'),
        ]);

        $this->postJson($this->api($this->two, 'schedule-config/capacity'), $config, $this->header)
            ->assertOk()
            ->assertJsonPath('config_overlaps', [[
                'tournament_id' => $this->one->id,
                'tournament_name' => 'Torneo Uno',
                'field_id' => $fieldOne->id,
                'field_name' => 'Cancha 1',
                'weekday' => 7,
                'start_time' => '09:00',
                'end_time' => '10:00',
            ]])
            // Solo informa: no descuenta espacios.
            ->assertJsonPath('matchdays.0.slots_occupied', 0);

        $this->putJson($this->api($this->two, 'schedule-config'), $config, $this->header)->assertOk()->assertJsonCount(3, 'windows');
        $this->getJson($this->api($this->two, 'schedule-config/capacity'), $this->header)->assertOk()->assertJsonMissingPath('config_overlaps');
    }

    public function test_manual_field_conflict_names_the_tournament_and_the_time_range(): void
    {
        $occupant = $this->occupy($this->one, $this->fields[0], '2026-11-08 08:00:00');
        $match = GameMatch::query()->where('tournament_id', $this->two->id)->orderBy('id')->firstOrFail();
        $url = "/api/leagues/{$this->league->id}/matches/{$match->id}/schedule";

        $this->patchJson($url, ['match_datetime' => '2026-11-08 09:00:00', 'field_id' => $this->fields[0]->id], $this->header)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'field_conflict')
            ->assertJsonPath('error.message', 'La cancha ya está ocupada por Torneo Uno el domingo 8 de noviembre, de 8:00 a 10:00.')
            ->assertJsonPath('error.tournament_id', $this->one->id)
            ->assertJsonPath('error.tournament_name', 'Torneo Uno')
            ->assertJsonPath('error.match.id', $occupant->id)
            ->assertJsonPath('error.match.home_team', Registration::query()->findOrFail($occupant->home_registration_id)->team?->name)
            ->assertJsonPath('error.match.date', '2026-11-08')
            ->assertJsonPath('error.match.field_id', $this->fields[0]->id)
            ->assertJsonPath('error.match.field_name', 'Cancha 1')
            ->assertJsonPath('error.match.start_time', '08:00')
            ->assertJsonPath('error.match.end_time', '10:00');

        // Pegado al final (10:00) sí se puede.
        $this->patchJson($url, ['match_datetime' => '2026-11-08 10:00:00', 'field_id' => $this->fields[0]->id], $this->header)->assertOk();
    }
}
