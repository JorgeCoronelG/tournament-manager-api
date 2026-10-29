<?php

namespace Tests\Feature\Referees;

use App\Core\Enum\MatchStatus;
use App\Core\Enum\Message;
use App\Core\Enum\Role;
use App\Core\Enum\TournamentGroupType;
use App\Core\Enum\TournamentStatus;
use App\Models\GameMatch;
use App\Models\League;
use App\Models\LeagueReferee;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Groups\InteractsWithGroups;
use Tests\TestCase;

class DetachRefereeValidationTest extends TestCase
{
    use InteractsWithGroups, RefreshDatabase;

    private League $league;

    private Tournament $tournament;

    /** @var array<string, string> */
    private array $header;

    private User $referee;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->league, $this->tournament, $this->header] = $this->tournamentWithAdminHeader();
        $this->referee = $this->createRefereeUser(['first_name' => 'Carlos', 'last_name' => 'Ramírez']);
        $this->linkReferee($this->league, $this->referee);
    }

    private function createRefereeUser(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->roles()->attach($this->createRole(Role::REFEREE));

        return $user;
    }

    private function linkReferee(League $league, User $user): LeagueReferee
    {
        return LeagueReferee::factory()->create(['league_id' => $league->id, 'user_id' => $user->id]);
    }

    private function detach(?League $league = null): TestResponse
    {
        $league ??= $this->league;

        return $this->deleteJson("/api/leagues/{$league->id}/referees/{$this->referee->id}", [], $this->header);
    }

    private function assignedMatch(MatchStatus $status = MatchStatus::SCHEDULED, ?Tournament $tournament = null): GameMatch
    {
        $tournament ??= $this->tournament;
        $this->teamsIn($tournament, $tournament->groups()->firstOrFail(), 2);
        $tournament->forceFill(['status' => TournamentStatus::IN_PROGRESS])->save();
        $this->postJson("/api/leagues/{$tournament->league_id}/tournaments/{$tournament->id}/matches/generate", ['shuffle' => false], $this->header)->assertOk();

        $match = GameMatch::query()->where('tournament_id', $tournament->id)->firstOrFail();
        $match->forceFill(['referee_user_id' => $this->referee->id, 'match_datetime' => '2026-11-07 10:00:00', 'status' => $status])->save();

        return $match;
    }

    private function assertBlocked(string $name = 'Carlos Ramírez'): void
    {
        $this->detach()->assertStatus(422)->assertExactJson(['code' => 422, 'error' => Message::getMessageRefereeHasAssignedMatches($name)]);
        $this->assertDatabaseHas('league_referees', ['league_id' => $this->league->id, 'user_id' => $this->referee->id, 'deleted_at' => null]);
    }

    public function test_detaches_a_referee_without_matches(): void
    {
        $this->detach()->assertNoContent();

        $this->assertSoftDeleted('league_referees', ['league_id' => $this->league->id, 'user_id' => $this->referee->id]);
    }

    public function test_a_scheduled_match_blocks_with_the_exact_message(): void
    {
        $this->assignedMatch();

        $this->detach()->assertStatus(422)->assertExactJson([
            'code' => 422,
            'error' => 'El árbitro <b>Carlos Ramírez</b> tiene partidos asignados en esta liga. Reasígnalos a otro árbitro antes de quitarlo.',
        ]);
        $this->assertNotSoftDeleted('league_referees', ['league_id' => $this->league->id, 'user_id' => $this->referee->id]);
    }

    /**
     * @return array<string, array{0: MatchStatus}>
     */
    public static function closedStatuses(): array
    {
        return ['played' => [MatchStatus::PLAYED], 'cancelled' => [MatchStatus::CANCELLED]];
    }

    /**
     * @dataProvider closedStatuses
     */
    public function test_played_or_cancelled_matches_do_not_block_and_keep_the_name(MatchStatus $status): void
    {
        $this->assignedMatch($status);

        $this->detach()->assertNoContent();

        $this->getJson("/api/leagues/{$this->league->id}/agenda?date_from=2026-11-01&date_to=2026-11-30", $this->header)
            ->assertOk()
            ->assertJsonPath('days.0.matches.0.referee.name', 'Carlos Ramírez');
    }

    public function test_a_soft_deleted_scheduled_match_does_not_block(): void
    {
        $this->assignedMatch()->delete();

        $this->detach()->assertNoContent();
    }

    public function test_a_scheduled_match_in_another_league_does_not_block(): void
    {
        $otherLeague = League::factory()->create();
        $this->linkReferee($otherLeague, $this->referee);
        $this->assignedMatch();
        $this->tournament->forceFill(['league_id' => $otherLeague->id])->save();

        $this->detach()->assertNoContent();
    }

    public function test_a_scheduled_playoff_match_blocks(): void
    {
        $match = $this->assignedMatch();
        $playoff = TournamentGroup::query()->forceCreate(['tournament_id' => $this->tournament->id, 'name' => 'Liguilla', 'type' => TournamentGroupType::PLAYOFF]);
        $match->forceFill(['group_id' => $playoff->id])->save();

        $this->assertBlocked();
    }

    public function test_the_name_is_escaped_and_the_email_is_the_fallback(): void
    {
        $this->referee->forceFill(['first_name' => '<script>x</script>', 'last_name' => ''])->save();
        $this->assignedMatch();

        $error = $this->detach()->assertStatus(422)->json('error');

        $this->assertStringContainsString('<b>&lt;script&gt;x&lt;/script&gt;</b>', $error);
        $this->assertStringNotContainsString('<script>', $error);

        $this->referee->forceFill(['first_name' => null, 'last_name' => null])->save();

        $this->assertStringContainsString("<b>{$this->referee->email}</b>", (string) $this->detach()->assertStatus(422)->json('error'));
    }

    public function test_detaching_from_one_league_keeps_the_other_link(): void
    {
        $otherLeague = League::factory()->create();
        $this->linkReferee($otherLeague, $this->referee);

        $this->detach()->assertNoContent();

        $this->assertNotSoftDeleted('league_referees', ['league_id' => $otherLeague->id, 'user_id' => $this->referee->id]);
    }
}
