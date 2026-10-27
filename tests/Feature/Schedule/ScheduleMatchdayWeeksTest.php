<?php

namespace Tests\Feature\Schedule;

use App\Core\Enum\TournamentStatus;
use App\Models\Field;
use App\Models\League;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Groups\InteractsWithGroups;
use Tests\TestCase;

class ScheduleMatchdayWeeksTest extends TestCase
{
    use InteractsWithGroups, RefreshDatabase;

    private function url(League $league, Tournament $tournament, string $suffix = ''): string
    {
        return "/api/leagues/{$league->id}/tournaments/{$tournament->id}/schedule-config{$suffix}";
    }

    /**
     * Torneo en curso con 4 equipos (3 jornadas de 2 partidos), una cancha y ventanas sábado y domingo de 8:00 a 9:00.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return array{0: League, 1: Tournament, 2: array<string, string>}
     */
    private function configured(array $blocks): array
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $this->teamsIn($tournament, $tournament->groups()->firstOrFail(), 4);
        $tournament->forceFill(['status' => TournamentStatus::IN_PROGRESS])->save();
        $this->postJson("/api/leagues/{$league->id}/tournaments/{$tournament->id}/matches/generate", [], $header)->assertOk();

        $field = Field::factory()->create(['league_id' => $league->id]);
        $windows = array_map(fn (int $weekday) => ['weekday' => $weekday, 'field_id' => $field->id, 'start_time' => '08:00', 'end_time' => '09:00'], [6, 7]);

        $this->putJson($this->url($league, $tournament), ['schedule_start_date' => '2026-11-02', 'windows' => $windows, 'blocks' => $blocks], $header)->assertOk();

        return [$league, $tournament, $header];
    }

    private function day(string $date): array
    {
        return ['date' => $date, 'field_id' => null];
    }

    public function test_without_blocks_each_matchday_takes_its_own_week(): void
    {
        [$league, $tournament, $header] = $this->configured([]);

        $this->getJson($this->url($league, $tournament, '/capacity'), $header)
            ->assertOk()
            ->assertJsonPath('matchdays.0.week', 1)
            ->assertJsonPath('matchdays.2.week', 3)
            ->assertJsonPath('matchdays.2.deficit', 0)
            ->assertJsonPath('warnings', []);
    }

    public function test_weeks_without_slots_are_skipped_and_do_not_consume_a_matchday(): void
    {
        [$league, $tournament, $header] = $this->configured([
            $this->day('2026-11-07'), $this->day('2026-11-08'), $this->day('2026-11-14'), $this->day('2026-11-15'),
        ]);

        $response = $this->getJson($this->url($league, $tournament, '/capacity'), $header)->assertOk();

        $response->assertJsonPath('matchdays.0.week', 3)
            ->assertJsonPath('matchdays.0.start_date', '2026-11-16')
            ->assertJsonPath('matchdays.0.end_date', '2026-11-22')
            ->assertJsonPath('matchdays.2.week', 5)
            ->assertJsonPath('warnings', []);
    }

    public function test_a_week_with_one_blocked_day_counts_with_its_remaining_slots(): void
    {
        [$league, $tournament, $header] = $this->configured([$this->day('2026-11-07')]);

        $this->getJson($this->url($league, $tournament, '/capacity'), $header)
            ->assertOk()
            ->assertJsonPath('matchdays.0.week', 1)
            ->assertJsonPath('matchdays.0.slots_available', 1)
            ->assertJsonPath('matchdays.0.deficit', 1)
            ->assertJsonCount(1, 'warnings');
    }

    public function test_matchdays_without_a_week_in_the_limit_have_full_deficit(): void
    {
        [$league, $tournament, $header] = $this->configured([]);
        $blocks = [];
        $windows = array_map(fn (array $w) => ['weekday' => $w['weekday'], 'field_id' => $w['field']['id'], 'start_time' => $w['start_time'], 'end_time' => $w['end_time']], $this->getJson($this->url($league, $tournament), $header)->json('windows'));

        for ($week = 0; $week < 52; $week++) {
            $monday = strtotime('2026-11-02 +'.($week * 7).' days');
            $blocks[] = $this->day(date('Y-m-d', $monday + 5 * 86400));
            $blocks[] = $this->day(date('Y-m-d', $monday + 6 * 86400));
        }

        $this->putJson($this->url($league, $tournament), [
            'schedule_start_date' => '2026-11-02',
            'windows' => $windows,
            'blocks' => $blocks,
        ], $header)->assertOk();

        $this->getJson($this->url($league, $tournament, '/capacity'), $header)
            ->assertOk()
            ->assertJsonPath('matchdays.0.week', null)
            ->assertJsonPath('matchdays.0.slots_available', 0)
            ->assertJsonPath('matchdays.0.deficit', 2)
            ->assertJsonCount(3, 'warnings');
    }
}
