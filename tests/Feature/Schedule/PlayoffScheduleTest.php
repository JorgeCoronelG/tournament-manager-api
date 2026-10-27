<?php

namespace Tests\Feature\Schedule;

use App\Core\Enum\MatchStatus;
use App\Core\Enum\Message;
use App\Core\Enum\TournamentStatus;
use App\Models\Field;
use App\Models\GameMatch;
use App\Models\League;
use App\Models\Registration;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Groups\InteractsWithGroups;
use Tests\TestCase;

class PlayoffScheduleTest extends TestCase
{
    use InteractsWithGroups, RefreshDatabase;

    private League $league;

    private Tournament $tournament;

    /** @var array<string, string> */
    private array $header;

    /** @var list<Registration> */
    private array $registrations;

    private Field $fieldA;

    private Field $fieldB;

    private int $principalId;

    private int $consolationId;

    private function api(string $path): string
    {
        return "/api/leagues/{$this->league->id}/tournaments/{$this->tournament->id}/{$path}";
    }

    /**
     * Torneo de 8 equipos con la fase regular jugada y dos liguillas de 4 (semifinal y final a un partido): «Principal»
     * con las posiciones 1 a 4 y «Consolación» con la 5 a 8, cerradas con las fechas dadas.
     *
     * @param  list<string>  $principalDates
     * @param  list<string>  $consolationDates
     */
    private function closedTournament(array $principalDates = ['2026-11-07', '2026-11-14'], array $consolationDates = ['2026-11-07', '2026-11-14']): void
    {
        [$this->league, $this->tournament, $this->header] = $this->tournamentWithAdminHeader();
        $group = $this->tournament->groups()->firstOrFail();
        $this->registrations = $this->teamsIn($this->tournament, $group, 8);
        $this->tournament->forceFill(['status' => TournamentStatus::IN_PROGRESS, 'rounds' => 1])->save();
        $this->postJson($this->api('matches/generate'), ['shuffle' => false], $this->header)->assertOk();

        $rank = array_flip(array_map(fn (Registration $r) => $r->id, $this->registrations));

        foreach (GameMatch::query()->get() as $match) {
            $homeWins = $rank[$match->home_registration_id] < $rank[$match->away_registration_id];
            $match->forceFill(['status' => MatchStatus::PLAYED, 'home_score' => $homeWins ? 1 : 0, 'away_score' => $homeWins ? 0 : 1])->save();
        }

        $ids = [];

        foreach ([['Principal', 1, 4], ['Consolación', 5, 8]] as $index => [$name, $from, $to]) {
            $ids[$index] = $this->postJson($this->groupsUrl($this->league, $this->tournament), [
                'type' => 'playoff', 'name' => $name, 'round_legs' => [1, 1],
                'sources' => [['source_group_id' => $group->id, 'position_start' => $from, 'position_end' => $to]],
            ], $this->header)->assertCreated()->json('id');
        }

        [$this->principalId, $this->consolationId] = $ids;

        $this->postJson($this->api('close-regular-phase'), ['liguillas' => [
            ['group_id' => $this->principalId, 'round_dates' => $principalDates],
            ['group_id' => $this->consolationId, 'round_dates' => $consolationDates],
        ]], $this->header)->assertOk();
        $this->tournament->refresh();

        $this->fieldA = Field::factory()->create(['league_id' => $this->league->id]);
        $this->fieldB = Field::factory()->create(['league_id' => $this->league->id]);
    }

    /**
     * Sábados de 8:00 a 12:00 en las dos canchas (8 espacios de 60 min) y domingos de 8:00 a 10:00 en la A.
     *
     * @param  list<array<string, mixed>>|null  $windows
     */
    private function configure(?array $windows = null): void
    {
        $windows ??= [
            ['weekday' => 6, 'field_id' => $this->fieldA->id, 'start_time' => '08:00', 'end_time' => '12:00'],
            ['weekday' => 6, 'field_id' => $this->fieldB->id, 'start_time' => '08:00', 'end_time' => '12:00'],
        ];

        $this->putJson($this->api('schedule-config'), ['schedule_start_date' => '2026-11-02', 'windows' => $windows, 'blocks' => []], $this->header)->assertOk();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function generate(array $body = []): TestResponse
    {
        return $this->postJson($this->api('schedule/generate'), ['phase' => 'playoffs'] + $body, $this->header);
    }

    /**
     * @return Collection<int, GameMatch>
     */
    private function playoffMatches(): Collection
    {
        return GameMatch::query()->whereNotNull('tie_id')->orderBy('id')->get();
    }

    private function finalOf(int $groupId): GameMatch
    {
        return GameMatch::query()->where('group_id', $groupId)->where('round_index', 2)->firstOrFail();
    }

    public function test_preview_does_not_save_and_generate_assigns_time_and_field_inside_each_date(): void
    {
        $this->closedTournament();
        $this->configure();
        $dates = $this->playoffMatches()->mapWithKeys(fn (GameMatch $match) => [$match->id => $match->scheduled_date?->toDateString()])->all();

        $this->postJson($this->api('schedule/preview'), ['phase' => 'playoffs'], $this->header)
            ->assertOk()->assertJsonPath('scheduled', 6)->assertJsonPath('unscheduled', 0)->assertJsonCount(6, 'matches');
        $this->assertSame(0, GameMatch::query()->whereNotNull('match_datetime')->whereNotNull('tie_id')->count());

        $this->generate()->assertOk()->assertJsonPath('scheduled', 6);

        foreach ($this->playoffMatches() as $match) {
            $this->assertNotNull($match->match_datetime);
            $this->assertNotNull($match->field_id);
            $this->assertSame($dates[$match->id], $match->match_datetime->format('Y-m-d'));
            $this->assertSame($dates[$match->id], $match->scheduled_date?->toDateString());
        }

        $semis = GameMatch::query()->where('round_index', 1)->whereNotNull('tie_id')->orderBy('id')->get();
        $this->assertSame($semis[0]->match_datetime?->format('H:i'), $semis[1]->match_datetime?->format('H:i'));
        $this->assertNotSame($semis[0]->field_id, $semis[1]->field_id);
    }

    public function test_aligned_finals_take_the_last_consecutive_hours_with_the_main_one_last(): void
    {
        $this->closedTournament();
        $this->configure();

        $this->generate(['align_finals' => true])->assertOk();

        $this->assertSame('11:00', $this->finalOf($this->principalId)->match_datetime?->format('H:i'));
        $this->assertSame('10:00', $this->finalOf($this->consolationId)->match_datetime?->format('H:i'));
    }

    public function test_finals_on_different_dates_are_not_moved_and_only_warn(): void
    {
        $this->closedTournament(['2026-11-07', '2026-11-14'], ['2026-11-07', '2026-11-21']);
        $this->configure();

        $response = $this->generate()->assertOk()->assertJsonPath('unscheduled', 0);

        $this->assertContains(Message::SCHEDULE_FINALS_NOT_ALIGNED, array_column((array) $response->json('warnings'), 'message'));
        $this->assertSame('2026-11-21', $this->finalOf($this->consolationId)->match_datetime?->format('Y-m-d'));
        $this->assertSame('08:00', $this->finalOf($this->consolationId)->match_datetime?->format('H:i'));
    }

    public function test_deficit_leaves_matches_without_time_and_warns_with_the_round_and_date(): void
    {
        $this->closedTournament();
        $this->configure([['weekday' => 6, 'field_id' => $this->fieldA->id, 'start_time' => '08:00', 'end_time' => '10:00']]);

        $this->generate()->assertOk()
            ->assertJsonPath('scheduled', 4)
            ->assertJsonPath('unscheduled', 2)
            ->assertJsonPath('warnings.0.message', 'Faltan 2 espacios el 07/11 para Semifinal.');

        $this->assertSame(2, $this->playoffMatches()->whereNull('match_datetime')->count());
    }

    public function test_dates_without_windows_get_no_time_and_capacity_warns(): void
    {
        $this->closedTournament();
        $this->configure([['weekday' => 7, 'field_id' => $this->fieldA->id, 'start_time' => '08:00', 'end_time' => '10:00']]);

        $this->generate()->assertOk()->assertJsonPath('scheduled', 0)->assertJsonPath('unscheduled', 6);

        $capacity = $this->getJson($this->api('schedule-config/capacity'), $this->header)->assertOk();
        $this->assertContains('No hay ventanas configuradas para el sábado 07/11.', array_column((array) $capacity->json('playoff_warnings'), 'message'));
        $this->assertSame(['Semifinal', 'Semifinal', 'Final', 'Final'], array_column((array) $capacity->json('playoff_rounds'), 'round_name'));
    }

    public function test_locked_and_played_matches_keep_their_slot_and_nobody_reuses_it(): void
    {
        $this->closedTournament();
        $this->configure();
        [$locked, $played] = $this->playoffMatches()->where('round_index', 1)->values()->all();
        $locked->forceFill(['match_datetime' => '2026-11-07 08:00:00', 'field_id' => $this->fieldA->id, 'schedule_locked' => true, 'referee_user_id' => null])->save();
        $played->forceFill(['match_datetime' => '2026-11-07 08:00:00', 'field_id' => $this->fieldB->id, 'status' => MatchStatus::PLAYED, 'home_score' => 1, 'away_score' => 0])->save();

        $this->generate()->assertOk()->assertJsonPath('locked_kept', 1)->assertJsonPath('played_kept', 1);

        $this->assertSame('2026-11-07 08:00', $locked->refresh()->match_datetime?->format('Y-m-d H:i'));
        $this->assertSame(0, GameMatch::query()->whereNotNull('tie_id')->whereNotIn('id', [$locked->id, $played->id])->whereDate('match_datetime', '2026-11-07')->whereTime('match_datetime', '08:00:00')->count());
    }

    public function test_slots_used_by_other_tournaments_of_the_league_are_not_reused(): void
    {
        $this->closedTournament();
        $this->configure();
        $other = Tournament::factory()->status(TournamentStatus::IN_PROGRESS)->create(['league_id' => $this->league->id]);
        $group = $other->groups()->firstOrFail();
        $busy = new GameMatch;
        $busy->forceFill([
            'tournament_id' => $other->id, 'group_id' => $group->id,
            'home_registration_id' => Registration::factory()->create(['tournament_id' => $other->id, 'group_id' => $group->id])->id,
            'away_registration_id' => Registration::factory()->create(['tournament_id' => $other->id, 'group_id' => $group->id])->id,
            'phase' => 'regular_stage', 'matchday' => 1, 'round_number' => 1, 'status' => MatchStatus::SCHEDULED,
            'match_datetime' => '2026-11-07 08:00:00', 'field_id' => $this->fieldA->id,
        ])->save();

        $this->generate()->assertOk();

        $this->assertSame(0, GameMatch::query()->where('tournament_id', $this->tournament->id)->whereNotNull('tie_id')
            ->whereDate('match_datetime', '2026-11-07')->whereTime('match_datetime', '08:00:00')->where('field_id', $this->fieldA->id)->count());
    }

    public function test_unscheduled_scope_keeps_assigned_matches_and_delete_clears_without_touching_dates(): void
    {
        $this->closedTournament();
        $this->configure();
        $this->generate()->assertOk();
        $before = $this->playoffMatches()->mapWithKeys(fn (GameMatch $match) => [$match->id => $match->match_datetime?->format('Y-m-d H:i')])->all();
        $freed = array_key_first($before);
        GameMatch::query()->whereKey($freed)->update(['match_datetime' => null, 'field_id' => null]);

        $this->generate(['scope' => 'unscheduled'])->assertOk()->assertJsonPath('scheduled', 1)->assertJsonPath('assigned_kept', 5);

        $after = $this->playoffMatches()->mapWithKeys(fn (GameMatch $match) => [$match->id => $match->match_datetime?->format('Y-m-d H:i')])->all();
        unset($before[$freed], $after[$freed]);
        $this->assertSame($before, $after);

        $locked = $this->playoffMatches()->first();
        $locked->forceFill(['schedule_locked' => true])->save();
        $dates = $this->playoffMatches()->map(fn (GameMatch $match) => $match->scheduled_date?->toDateString())->all();

        $this->deleteJson($this->api('schedule?phase=playoffs'), [], $this->header)->assertOk()->assertJsonPath('cleared', 5);

        $this->assertNotNull($locked->refresh()->match_datetime);
        $this->assertSame(5, $this->playoffMatches()->whereNull('match_datetime')->count());
        $this->assertSame($dates, $this->playoffMatches()->map(fn (GameMatch $match) => $match->scheduled_date?->toDateString())->all());
    }

    public function test_playoff_generation_requires_the_tournament_in_playoffs(): void
    {
        $this->closedTournament();
        $this->configure();
        $this->tournament->forceFill(['status' => TournamentStatus::IN_PROGRESS])->save();

        $this->generate()->assertUnprocessable()->assertJsonPath('error', Message::SCHEDULE_PLAYOFFS_ONLY);
    }

    public function test_playoff_matches_can_be_edited_swapped_and_get_a_referee_even_without_teams(): void
    {
        $this->closedTournament();
        $this->configure();
        $final = $this->finalOf($this->principalId);
        $other = $this->finalOf($this->consolationId);
        $this->assertNull($final->home_registration_id);
        $url = fn (GameMatch $match, string $suffix) => "/api/leagues/{$this->league->id}/matches/{$match->id}/{$suffix}";

        $this->patchJson($url($final, 'schedule'), ['match_datetime' => '2026-11-15 10:00:00', 'field_id' => $this->fieldA->id], $this->header)
            ->assertOk()->assertJsonPath('schedule_locked', true);
        $this->assertSame('2026-11-15', $final->refresh()->scheduled_date?->toDateString());

        $this->patchJson($url($other, 'schedule'), ['match_datetime' => '2026-11-14 10:00:00', 'field_id' => $this->fieldB->id], $this->header)->assertOk();

        $this->postJson($url($final, 'swap-schedule'), ['with_match_id' => $other->id], $this->header)->assertOk();
        $this->assertSame('2026-11-14', $final->refresh()->scheduled_date?->toDateString());
        $this->assertSame('2026-11-15', $other->refresh()->scheduled_date?->toDateString());

        $this->getJson($url($final, 'available-slots?date=2026-11-14'), $this->header)->assertOk();
        $this->getJson($url($final, 'available-referees'), $this->header)->assertOk();
    }

    public function test_advancing_a_winner_into_a_timed_match_only_warns_about_a_team_clash(): void
    {
        $this->closedTournament();
        $this->configure();
        $semi = GameMatch::query()->where('group_id', $this->principalId)->where('round_index', 1)->orderBy('bracket_position')->firstOrFail();
        $final = $this->finalOf($this->principalId);
        $final->forceFill(['match_datetime' => '2026-11-14 11:00:00', 'field_id' => $this->fieldA->id])->save();

        // El local de la semifinal (que gana 1-0) ya juega a esa hora en otro torneo de la liga.
        $other = Tournament::factory()->status(TournamentStatus::IN_PROGRESS)->create(['league_id' => $this->league->id]);
        $group = $other->groups()->firstOrFail();
        $busy = new GameMatch;
        $busy->forceFill([
            'tournament_id' => $other->id, 'group_id' => $group->id,
            'home_registration_id' => Registration::factory()->create(['tournament_id' => $other->id, 'group_id' => $group->id, 'team_id' => Registration::query()->findOrFail($semi->home_registration_id)->team_id])->id,
            'away_registration_id' => Registration::factory()->create(['tournament_id' => $other->id, 'group_id' => $group->id])->id,
            'phase' => 'regular_stage', 'matchday' => 1, 'round_number' => 1, 'status' => MatchStatus::SCHEDULED,
            'match_datetime' => '2026-11-14 11:30:00', 'field_id' => $this->fieldB->id,
        ])->save();

        $response = $this->patchJson($this->api("matches/{$semi->id}/result"), ['home_goals' => 1, 'away_goals' => 0], $this->header)->assertOk();

        $this->assertSame('team_conflict', $response->json('warnings.0.code'));
        $this->assertNotNull($response->json('advanced_to'));
        $this->assertSame(MatchStatus::PLAYED, $semi->refresh()->status);
    }

    public function test_close_preview_reports_schedule_warnings_without_blocking(): void
    {
        [$this->league, $this->tournament, $this->header] = $this->tournamentWithAdminHeader();
        $group = $this->tournament->groups()->firstOrFail();
        $this->registrations = $this->teamsIn($this->tournament, $group, 4);
        $this->tournament->forceFill(['status' => TournamentStatus::IN_PROGRESS])->save();
        $this->postJson($this->api('matches/generate'), ['shuffle' => false], $this->header)->assertOk();
        $rank = array_flip(array_map(fn (Registration $r) => $r->id, $this->registrations));

        foreach (GameMatch::query()->get() as $match) {
            $homeWins = $rank[$match->home_registration_id] < $rank[$match->away_registration_id];
            $match->forceFill(['status' => MatchStatus::PLAYED, 'home_score' => $homeWins ? 1 : 0, 'away_score' => $homeWins ? 0 : 1])->save();
        }

        $this->postJson($this->groupsUrl($this->league, $this->tournament), [
            'type' => 'playoff', 'name' => 'Liguilla', 'round_legs' => [1, 1],
            'sources' => [['source_group_id' => $group->id, 'position_start' => 1, 'position_end' => 4]],
        ], $this->header)->assertCreated();

        $response = $this->getJson($this->api('close-regular-phase/preview'), $this->header)->assertOk()->assertJsonPath('blockers', []);

        $messages = array_column((array) $response->json('schedule_warnings'), 'message');
        $this->assertCount(2, $messages);
        $this->assertStringStartsWith('No hay ventanas configuradas para el', $messages[0]);
    }
}
