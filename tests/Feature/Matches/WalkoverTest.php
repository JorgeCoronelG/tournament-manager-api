<?php

namespace Tests\Feature\Matches;

use App\Core\Enum\MatchStatus;
use App\Core\Enum\Message;
use App\Core\Enum\TournamentStatus;
use App\Core\Enum\WalkoverCause;
use App\Models\GameMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WalkoverTest extends TestCase
{
    use InteractsWithWalkovers, RefreshDatabase;

    public function test_regular_stage_walkover_gives_3_0_with_the_standings_and_no_match_events(): void
    {
        [$league, $tournament, $header, $teams] = $this->running(4);
        $match = GameMatch::query()->firstOrFail();
        $loser = $match->home_registration_id;
        $winner = $match->away_registration_id;

        $response = $this->walkover($league, $tournament, $header, $match, $loser, ['reason' => 'No llegaron'])->assertOk()
            ->assertJsonPath('status', 'played')
            ->assertJsonPath('is_walkover', true)
            ->assertJsonPath('walkover_cause', 'no_show')
            ->assertJsonPath('walkover_loser_registration_id', $loser)
            ->assertJsonPath('walkover_reason', 'No llegaron')
            ->assertJsonPath('home_score', 0)
            ->assertJsonPath('away_score', 3)
            ->assertJsonPath('home_penalties', null);

        $this->assertNotNull($response->json('id'));
        $this->assertNotNull($match->fresh()->walkover_registered_by_user_id);

        $rows = collect($this->getJson($this->base($league, $tournament).'/standings', $header)->assertOk()->json('groups.0.rows'))->keyBy('registration_id');
        $this->assertSame(3, $rows[$winner]['points']);
        $this->assertSame(3, $rows[$winner]['goals_for']);
        $this->assertSame(0, $rows[$winner]['goals_against']);
        $this->assertSame(0, $rows[$loser]['points']);
        $this->assertSame(3, $rows[$loser]['goals_against']);
        $this->assertSame(0, DB::table('match_events')->count());
    }

    public function test_walkover_uses_the_tournament_goals(): void
    {
        [$league, $tournament, $header] = $this->running(4);
        $tournament->forceFill(['walkover_goals' => 2])->save();
        $match = GameMatch::query()->firstOrFail();

        $this->walkover($league, $tournament->refresh(), $header, $match, $match->away_registration_id)->assertOk()
            ->assertJsonPath('home_score', 2)
            ->assertJsonPath('away_score', 0);
    }

    public function test_a_played_match_cannot_be_walked_over(): void
    {
        [$league, $tournament, $header] = $this->running(4);
        $match = GameMatch::query()->firstOrFail();
        $match->forceFill(['status' => MatchStatus::PLAYED, 'home_score' => 1, 'away_score' => 0])->save();

        $this->walkover($league, $tournament, $header, $match, $match->home_registration_id)->assertStatus(422)
            ->assertExactJson(['code' => 422, 'error' => Message::MATCH_ALREADY_PLAYED]);
    }

    public function test_is_idempotent_with_the_same_loser_and_rejects_another_loser(): void
    {
        [$league, $tournament, $header] = $this->running(4);
        $match = GameMatch::query()->firstOrFail();

        $this->walkover($league, $tournament, $header, $match, $match->home_registration_id)->assertOk();
        $this->walkover($league, $tournament, $header, $match, $match->home_registration_id)->assertOk()->assertJsonPath('home_score', 0)->assertJsonPath('away_score', 3);
        $this->walkover($league, $tournament, $header, $match, $match->away_registration_id)->assertStatus(422)
            ->assertJsonPath('error', Message::WALKOVER_OTHER_LOSER);
    }

    public function test_the_loser_must_play_the_match_and_the_tournament_must_be_open(): void
    {
        [$league, $tournament, $header, $teams] = $this->running(4);
        $match = GameMatch::query()->firstOrFail();
        $outsider = collect($teams)->first(fn ($team) => ! in_array($team->id, [$match->home_registration_id, $match->away_registration_id], true));

        $this->walkover($league, $tournament, $header, $match, $outsider->id)->assertStatus(422)->assertJsonStructure(['error' => ['loser_registration_id']]);
        $this->postJson($this->base($league, $tournament)."/matches/{$match->id}/walkover", [], $header)->assertStatus(422);

        $tournament->forceFill(['status' => TournamentStatus::FINISHED])->save();
        $this->walkover($league, $tournament->refresh(), $header, $match, $match->home_registration_id)->assertStatus(422);
    }

    public function test_undoing_a_regular_stage_walkover_clears_it(): void
    {
        [$league, $tournament, $header] = $this->running(4);
        $match = GameMatch::query()->firstOrFail();
        $this->walkover($league, $tournament, $header, $match, $match->home_registration_id)->assertOk();

        $this->deleteJson($this->base($league, $tournament)."/matches/{$match->id}/result", [], $header)->assertOk()
            ->assertJsonPath('status', 'scheduled')
            ->assertJsonPath('is_walkover', false)
            ->assertJsonPath('walkover_cause', null)
            ->assertJsonPath('walkover_loser_registration_id', null)
            ->assertJsonPath('walkover_reason', null);

        $fresh = $match->fresh();
        $this->assertNull($fresh->home_score);
        $this->assertNull($fresh->walkover_registered_by_user_id);
    }

    public function test_a_walkover_has_to_be_undone_before_entering_another_score(): void
    {
        [$league, $tournament, $header] = $this->running(4);
        $match = GameMatch::query()->firstOrFail();
        $this->walkover($league, $tournament, $header, $match, $match->home_registration_id)->assertOk();

        $this->patchJson($this->base($league, $tournament)."/matches/{$match->id}/result", ['home_score' => 1, 'away_score' => 0], $header)
            ->assertStatus(422)->assertJsonPath('error', Message::MATCH_WALKOVER_UNDO_FIRST);
    }

    public function test_a_single_match_playoff_walkover_advances_the_rival(): void
    {
        [$league, $tournament, $header, $playoffId, $teams] = $this->inPlayoffs([1, 1]);
        $semifinal = $this->tie($playoffId, 1, 1);
        [$match] = $this->tieMatches($semifinal);
        $loser = $semifinal->registration_a_id;
        $winner = $semifinal->registration_b_id;

        $this->walkover($league, $tournament, $header, $match, $loser)->assertOk()
            ->assertJsonPath('tie.winner_registration_id', $winner)
            ->assertJsonPath('tie.decided_by', 'walkover')
            ->assertJsonPath('advanced_to.slot', 'a');

        $this->assertSame($winner, $this->tie($playoffId, 2, 1)->registration_a_id);
        $fresh = $match->fresh();
        $this->assertSame(3, (int) ($fresh->home_registration_id === $winner ? $fresh->home_score : $fresh->away_score));
    }

    public function test_a_two_leg_playoff_walkover_fixes_the_winner_even_if_the_first_leg_favored_the_loser(): void
    {
        [$league, $tournament, $header, $playoffId] = $this->inPlayoffs([2, 1]);
        $semifinal = $this->tie($playoffId, 1, 1);
        [$first, $second] = $this->tieMatches($semifinal);
        $leader = $semifinal->registration_a_id;
        $rival = $semifinal->registration_b_id;

        $leaderHome = $first->home_registration_id === $leader;
        $this->patchJson($this->base($league, $tournament)."/matches/{$first->id}/result", ['home_score' => $leaderHome ? 9 : 0, 'away_score' => $leaderHome ? 0 : 9], $header)->assertOk();

        $this->walkover($league, $tournament, $header, $second, $leader)->assertOk()
            ->assertJsonPath('tie.winner_registration_id', $rival)
            ->assertJsonPath('tie.decided_by', 'walkover');

        $this->assertSame($rival, $this->tie($playoffId, 2, 1)->registration_a_id);
        $this->assertFalse($first->fresh()->is_walkover);
        $this->assertTrue($second->fresh()->is_walkover);
    }

    public function test_a_no_show_in_the_first_leg_defaults_both_legs(): void
    {
        [$league, $tournament, $header, $playoffId] = $this->inPlayoffs([2, 1]);
        $semifinal = $this->tie($playoffId, 1, 1);
        [$first, $second] = $this->tieMatches($semifinal);

        $this->walkover($league, $tournament, $header, $first, $semifinal->registration_a_id)->assertOk()
            ->assertJsonPath('tie.winner_registration_id', $semifinal->registration_b_id)
            ->assertJsonPath('tie.decided_by', 'walkover');

        foreach ([$first, $second] as $match) {
            $fresh = $match->fresh();
            $this->assertSame(MatchStatus::PLAYED, $fresh->status);
            $this->assertTrue($fresh->is_walkover);
            $this->assertSame(WalkoverCause::NO_SHOW, $fresh->walkover_cause);
            $this->assertSame($semifinal->registration_a_id, $fresh->walkover_loser_registration_id);
        }
    }

    public function test_undoing_a_playoff_walkover_removes_the_winner_and_the_advance(): void
    {
        [$league, $tournament, $header, $playoffId] = $this->inPlayoffs([2, 1]);
        $semifinal = $this->tie($playoffId, 1, 1);
        [$first, $second] = $this->tieMatches($semifinal);
        $this->walkover($league, $tournament, $header, $first, $semifinal->registration_a_id)->assertOk();

        $this->deleteJson($this->base($league, $tournament)."/matches/{$second->id}/result", [], $header)->assertOk()
            ->assertJsonPath('tie.winner_registration_id', null)
            ->assertJsonPath('tie.decided_by', null);

        $this->assertNull($this->tie($playoffId, 2, 1)->registration_a_id);

        foreach ([$first, $second] as $match) {
            $fresh = $match->fresh();
            $this->assertSame(MatchStatus::SCHEDULED, $fresh->status);
            $this->assertFalse($fresh->is_walkover);
        }
    }

    public function test_undoing_a_playoff_walkover_is_blocked_when_the_next_round_has_results(): void
    {
        [$league, $tournament, $header, $playoffId] = $this->inPlayoffs([1, 1]);
        $first = $this->tie($playoffId, 1, 1);
        $second = $this->tie($playoffId, 1, 2);
        [$walked] = $this->tieMatches($first);
        [$played] = $this->tieMatches($second);

        $this->walkover($league, $tournament, $header, $walked, $first->registration_a_id)->assertOk();
        $this->patchJson($this->base($league, $tournament)."/matches/{$played->id}/result", ['home_score' => 1, 'away_score' => 0], $header)->assertOk();
        [$final] = $this->tieMatches($this->tie($playoffId, 2, 1));
        $this->patchJson($this->base($league, $tournament)."/matches/{$final->id}/result", ['home_score' => 2, 'away_score' => 0], $header)->assertOk();

        $this->deleteJson($this->base($league, $tournament)."/matches/{$walked->id}/result", [], $header)->assertStatus(422)
            ->assertJsonPath('error', Message::MATCH_NEXT_ROUND_HAS_RESULTS);
    }
}
