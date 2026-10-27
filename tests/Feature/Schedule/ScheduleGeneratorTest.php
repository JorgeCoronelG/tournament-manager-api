<?php

namespace Tests\Feature\Schedule;

use App\Core\Enum\MatchStatus;
use App\Core\Enum\Message;
use App\Core\Enum\TournamentStatus;
use App\Models\Field;
use App\Models\GameMatch;
use App\Models\League;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Groups\InteractsWithGroups;
use Tests\TestCase;

class ScheduleGeneratorTest extends TestCase
{
    use InteractsWithGroups, RefreshDatabase;

    /** @var list<Field> */
    private array $fields = [];

    private function api(League $league, Tournament $tournament, string $path): string
    {
        return "/api/leagues/{$league->id}/tournaments/{$tournament->id}/{$path}";
    }

    /**
     * Torneo en curso con 6 equipos (5 jornadas de 3 partidos) y 2 canchas con ventanas de 8:00 a 10:00 el sábado y el
     * domingo (4 espacios por día, 8 por semana) desde el lunes 2026-11-02.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return array{0: League, 1: Tournament, 2: array<string, string>}
     */
    private function configured(array $blocks = [], bool $calendar = true): array
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $this->teamsIn($tournament, $tournament->groups()->firstOrFail(), 6);
        $tournament->forceFill(['status' => TournamentStatus::IN_PROGRESS])->save();

        if ($calendar) {
            $this->postJson($this->api($league, $tournament, 'matches/generate'), ['shuffle' => false], $header)->assertOk();
        }

        $this->fields = [Field::factory()->create(['league_id' => $league->id]), Field::factory()->create(['league_id' => $league->id])];
        $this->saveConfig($league, $tournament, $header, array_map(fn (array $w) => $w, $this->windows([6, 7])), $blocks);

        return [$league, $tournament, $header];
    }

    /**
     * @param  list<int>  $weekdays
     * @return list<array<string, mixed>>
     */
    private function windows(array $weekdays): array
    {
        $windows = [];

        foreach ($weekdays as $weekday) {
            foreach ($this->fields as $field) {
                $windows[] = ['weekday' => $weekday, 'field_id' => $field->id, 'start_time' => '08:00', 'end_time' => '10:00'];
            }
        }

        return $windows;
    }

    /**
     * @param  list<array<string, mixed>>  $windows
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, string>  $header
     */
    private function saveConfig(League $league, Tournament $tournament, array $header, array $windows, array $blocks = []): void
    {
        $this->putJson($this->api($league, $tournament, 'schedule-config'), ['schedule_start_date' => '2026-11-02', 'windows' => $windows, 'blocks' => $blocks], $header)->assertOk();
    }

    /**
     * @return array<int, string|null> Hora de cada partido por id.
     */
    private function times(Tournament $tournament): array
    {
        return GameMatch::query()->where('tournament_id', $tournament->id)->orderBy('id')->pluck('match_datetime', 'id')
            ->map(fn ($at) => $at?->format('Y-m-d H:i'))->all();
    }

    public function test_preview_does_not_save_generate_does_and_delete_clears(): void
    {
        [$league, $tournament, $header] = $this->configured();

        $this->postJson($this->api($league, $tournament, 'schedule/preview'), [], $header)
            ->assertOk()->assertJsonPath('scheduled', 15)->assertJsonPath('unscheduled', 0)->assertJsonCount(15, 'matches');
        $this->assertSame(0, GameMatch::query()->whereNotNull('match_datetime')->count());

        $this->postJson($this->api($league, $tournament, 'schedule/generate'), [], $header)->assertOk()->assertJsonPath('scheduled', 15);
        $this->assertSame(15, GameMatch::query()->whereNotNull('match_datetime')->whereNotNull('field_id')->count());

        $this->deleteJson($this->api($league, $tournament, 'schedule'), [], $header)->assertOk()->assertJsonPath('cleared', 15);
        $this->assertSame(0, GameMatch::query()->whereNotNull('match_datetime')->count());
    }

    public function test_balanced_spreads_days_and_fields_without_repeating_a_team_per_day(): void
    {
        [$league, $tournament, $header] = $this->configured();

        $this->postJson($this->api($league, $tournament, 'schedule/generate'), ['mode' => 'balanced'], $header)->assertOk();

        foreach (range(1, 5) as $matchday) {
            $matches = GameMatch::query()->where('matchday', $matchday)->get();
            $perDay = $matches->countBy(fn (GameMatch $match) => $match->match_datetime?->format('Y-m-d'));

            $this->assertSame(2, $perDay->count(), "jornada {$matchday} en dos días");
            $this->assertLessThanOrEqual(1, $perDay->max() - $perDay->min());

            $teams = $matches->flatMap(fn (GameMatch $match) => [$match->home_registration_id, $match->away_registration_id]);
            $this->assertSame($teams->count(), $teams->unique()->count());
        }

        $saturday = GameMatch::query()->whereDate('match_datetime', '2026-11-07')->where('matchday', 1)->pluck('field_id');
        $this->assertSame(2, $saturday->unique()->count());
    }

    public function test_random_with_the_same_seed_repeats_the_result(): void
    {
        [$league, $tournament, $header] = $this->configured();
        $body = ['mode' => 'random', 'seed' => 42];

        $this->postJson($this->api($league, $tournament, 'schedule/generate'), $body, $header)->assertOk();
        $first = GameMatch::query()->orderBy('id')->get(['match_datetime', 'field_id'])->toArray();

        $this->postJson($this->api($league, $tournament, 'schedule/generate'), $body, $header)->assertOk();

        $this->assertSame($first, GameMatch::query()->orderBy('id')->get(['match_datetime', 'field_id'])->toArray());
    }

    public function test_preview_returns_a_seed_that_generate_reproduces(): void
    {
        [$league, $tournament, $header] = $this->configured();

        $preview = $this->postJson($this->api($league, $tournament, 'schedule/preview'), ['mode' => 'random'], $header)->assertOk();
        $seed = $preview->json('seed');
        $this->assertIsInt($seed);

        $generated = $this->postJson($this->api($league, $tournament, 'schedule/generate'), ['mode' => 'random', 'seed' => $seed], $header)
            ->assertOk()->assertJsonPath('seed', $seed);

        $this->assertSame($preview->json('matches'), $generated->json('matches'));
        $this->postJson($this->api($league, $tournament, 'schedule/preview'), [], $header)->assertJsonPath('seed', null);
    }

    public function test_a_fully_blocked_week_is_skipped(): void
    {
        $day = fn (string $date) => ['date' => $date, 'field_id' => null];
        [$league, $tournament, $header] = $this->configured([$day('2026-11-07'), $day('2026-11-08')]);

        $this->postJson($this->api($league, $tournament, 'schedule/preview'), [], $header)
            ->assertOk()
            ->assertJsonPath('matchdays.0.matchday', 1)
            ->assertJsonPath('matchdays.0.week_start', '2026-11-09')
            ->assertJsonPath('matchdays.0.week_end', '2026-11-15');
    }

    public function test_matchdays_with_fewer_slots_than_matches_leave_the_rest_unscheduled(): void
    {
        [$league, $tournament, $header] = $this->configured();
        $this->saveConfig($league, $tournament, $header, [['weekday' => 6, 'field_id' => $this->fields[0]->id, 'start_time' => '08:00', 'end_time' => '09:00']]);

        $this->postJson($this->api($league, $tournament, 'schedule/generate'), [], $header)
            ->assertOk()
            ->assertJsonPath('scheduled', 5)
            ->assertJsonPath('unscheduled', 10)
            ->assertJsonPath('matchdays.0.unscheduled', 2)
            ->assertJsonPath('warnings.0', ['matchday' => 1, 'unscheduled' => 2, 'message' => 'Faltan 2 espacios en la jornada 1.']);

        $this->saveConfig($league, $tournament, $header, [['weekday' => 6, 'field_id' => $this->fields[0]->id, 'start_time' => '08:00', 'end_time' => '10:00']]);
        $this->postJson($this->api($league, $tournament, 'schedule/preview'), [], $header)
            ->assertJsonPath('warnings.0.message', 'Falta 1 espacio en la jornada 1.');

        $this->assertSame(5, GameMatch::query()->whereNotNull('match_datetime')->count());
    }

    public function test_locked_and_played_matches_are_not_moved_and_keep_their_slot(): void
    {
        [$league, $tournament, $header] = $this->configured();
        [$locked, $played] = GameMatch::query()->where('matchday', 1)->orderBy('id')->get()->all();

        $locked->forceFill(['schedule_locked' => true, 'match_datetime' => '2026-11-07 08:00:00', 'field_id' => $this->fields[0]->id])->save();
        $played->forceFill(['status' => MatchStatus::PLAYED, 'home_score' => 1, 'away_score' => 0, 'match_datetime' => '2026-11-07 08:00:00', 'field_id' => $this->fields[1]->id])->save();

        $this->postJson($this->api($league, $tournament, 'schedule/generate'), [], $header)
            ->assertOk()->assertJsonPath('locked_kept', 1)->assertJsonPath('played_kept', 1)->assertJsonPath('scheduled', 13);

        $this->assertSame('2026-11-07 08:00', $locked->refresh()->match_datetime?->format('Y-m-d H:i'));
        $this->assertSame($this->fields[1]->id, $played->refresh()->field_id);
        $this->assertSame(0, GameMatch::query()->where('id', '!=', $locked->id)->where('id', '!=', $played->id)
            ->whereDate('match_datetime', '2026-11-07')->whereTime('match_datetime', '08:00:00')->count());
    }

    public function test_unscheduled_scope_does_not_move_what_is_already_assigned(): void
    {
        [$league, $tournament, $header] = $this->configured();
        $this->postJson($this->api($league, $tournament, 'schedule/generate'), [], $header)->assertOk();
        $before = $this->times($tournament);
        $freed = array_key_first($before);
        GameMatch::query()->whereKey($freed)->update(['match_datetime' => null, 'field_id' => null]);

        $this->postJson($this->api($league, $tournament, 'schedule/generate'), ['scope' => 'unscheduled'], $header)
            ->assertOk()->assertJsonPath('scheduled', 1)->assertJsonPath('assigned_kept', 14);

        $after = $this->times($tournament);
        unset($before[$freed], $after[$freed]);
        $this->assertSame($before, $after);
        $this->assertNotNull($this->times($tournament)[$freed]);
    }

    public function test_requires_a_running_tournament(): void
    {
        [$league, $tournament, $header] = $this->configured();

        foreach ([TournamentStatus::PLAYOFFS, TournamentStatus::PLANNING] as $status) {
            $tournament->forceFill(['status' => $status])->save();

            $this->postJson($this->api($league, $tournament, 'schedule/generate'), [], $header)
                ->assertUnprocessable()->assertJsonPath('error', Message::SCHEDULE_ONLY_IN_PROGRESS);
        }
    }

    public function test_requires_the_matchup_calendar(): void
    {
        [$league, $tournament, $header] = $this->configured(calendar: false);

        $this->postJson($this->api($league, $tournament, 'schedule/generate'), [], $header)
            ->assertUnprocessable()->assertJsonPath('error', Message::SCHEDULE_NO_CALENDAR);
    }

    public function test_requires_the_schedule_config(): void
    {
        [$league, $tournament, $header] = $this->configured();
        $this->saveConfig($league, $tournament, $header, []);

        $this->postJson($this->api($league, $tournament, 'schedule/generate'), [], $header)
            ->assertUnprocessable()->assertJsonPath('error', Message::SCHEDULE_NOT_CONFIGURED);
    }

    public function test_rejects_invalid_mode_and_scope(): void
    {
        [$league, $tournament, $header] = $this->configured();

        $this->postJson($this->api($league, $tournament, 'schedule/generate'), ['mode' => 'x', 'scope' => 'y'], $header)
            ->assertUnprocessable()->assertJsonValidationErrorFor('mode', 'error')->assertJsonValidationErrorFor('scope', 'error');
    }

    public function test_schedule_is_outdated_after_changing_the_config(): void
    {
        [$league, $tournament, $header] = $this->configured();
        $capacity = $this->api($league, $tournament, 'schedule-config/capacity');

        $this->getJson($capacity, $header)->assertJsonPath('schedule_outdated', false);

        $this->postJson($this->api($league, $tournament, 'schedule/generate'), [], $header)->assertOk();
        $this->getJson($capacity, $header)->assertJsonPath('schedule_outdated', false);

        $this->saveConfig($league, $tournament, $header, $this->windows([6]));
        $this->getJson($capacity, $header)->assertJsonPath('schedule_outdated', true);
    }

    public function test_the_calendar_shows_the_time_and_field_of_each_match(): void
    {
        [$league, $tournament, $header] = $this->configured();
        $this->postJson($this->api($league, $tournament, 'schedule/generate'), [], $header)->assertOk();

        $this->getJson($this->api($league, $tournament, 'matches?per_page=50'), $header)
            ->assertOk()
            ->assertJsonStructure(['data' => [['match_datetime', 'field' => ['id', 'name']]]]);
    }
}
