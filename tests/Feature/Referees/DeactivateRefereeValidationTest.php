<?php

namespace Tests\Feature\Referees;

use App\Core\Enum\LeagueRefereeStatus;
use App\Core\Enum\MatchStatus;
use App\Core\Enum\Role as RoleEnum;
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

class DeactivateRefereeValidationTest extends TestCase
{
    use InteractsWithGroups, RefreshDatabase;

    private League $league;

    private Tournament $tournament;

    /** @var array<string, string> */
    private array $header;

    private User $referee;

    private LeagueReferee $link;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->league, $this->tournament, $this->header] = $this->tournamentWithAdminHeader();
        $this->referee = User::factory()->create(['first_name' => 'Carlos', 'last_name' => 'Ramírez']);
        $this->referee->roles()->attach($this->createRole(RoleEnum::REFEREE));
        $this->link = LeagueReferee::factory()->create(['league_id' => $this->league->id, 'user_id' => $this->referee->id]);
    }

    private function setStatus(string $status): TestResponse
    {
        return $this->patchJson("/api/leagues/{$this->league->id}/referees/{$this->referee->id}/status", ['status' => $status], $this->header);
    }

    private function assignedMatch(MatchStatus $status = MatchStatus::SCHEDULED): GameMatch
    {
        $this->teamsIn($this->tournament, $this->tournament->groups()->firstOrFail(), 2);
        $this->tournament->forceFill(['status' => TournamentStatus::IN_PROGRESS])->save();
        $this->postJson("/api/leagues/{$this->league->id}/tournaments/{$this->tournament->id}/matches/generate", ['shuffle' => false], $this->header)->assertOk();

        $match = GameMatch::query()->where('tournament_id', $this->tournament->id)->firstOrFail();
        $match->forceFill(['referee_user_id' => $this->referee->id, 'match_datetime' => '2026-11-07 10:00:00', 'status' => $status])->save();

        return $match;
    }

    private function assertStillActive(): void
    {
        $this->assertSame(LeagueRefereeStatus::ACTIVE, $this->link->fresh()->status);
    }

    public function test_deactivates_a_referee_without_matches(): void
    {
        $this->setStatus('inactive')->assertOk()->assertJsonPath('status', 'inactive');
    }

    public function test_a_scheduled_match_blocks_with_the_exact_message(): void
    {
        $this->assignedMatch();

        $this->setStatus('inactive')->assertStatus(422)->assertExactJson([
            'code' => 422,
            'error' => 'El árbitro <b>Carlos Ramírez</b> tiene partidos asignados en esta liga. Reasígnalos a otro árbitro antes de darlo de baja.',
        ]);
        $this->assertStillActive();
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
    public function test_played_or_cancelled_matches_do_not_block(MatchStatus $status): void
    {
        $this->assignedMatch($status);

        $this->setStatus('inactive')->assertOk();
    }

    public function test_a_soft_deleted_scheduled_match_does_not_block(): void
    {
        $this->assignedMatch()->delete();

        $this->setStatus('inactive')->assertOk();
    }

    public function test_a_scheduled_match_in_another_league_does_not_block(): void
    {
        $otherLeague = League::factory()->create();
        $this->assignedMatch();
        $this->tournament->forceFill(['league_id' => $otherLeague->id])->save();

        $this->setStatus('inactive')->assertOk();
    }

    public function test_a_scheduled_playoff_match_blocks(): void
    {
        $match = $this->assignedMatch();
        $playoff = TournamentGroup::query()->forceCreate(['tournament_id' => $this->tournament->id, 'name' => 'Liguilla', 'type' => TournamentGroupType::PLAYOFF]);
        $match->forceFill(['group_id' => $playoff->id])->save();

        $this->setStatus('inactive')->assertStatus(422);
        $this->assertStillActive();
    }

    public function test_deactivating_works_after_reassigning_the_match_to_another_referee(): void
    {
        $match = $this->assignedMatch();
        $other = User::factory()->create();
        $other->roles()->attach($this->createRole(RoleEnum::REFEREE));
        LeagueReferee::factory()->create(['league_id' => $this->league->id, 'user_id' => $other->id]);

        $this->setStatus('inactive')->assertStatus(422);

        $match->forceFill(['referee_user_id' => $other->id])->save();

        $this->setStatus('inactive')->assertOk()->assertJsonPath('status', 'inactive');
    }

    public function test_activating_is_always_allowed(): void
    {
        $this->assignedMatch();
        $this->link->forceFill(['status' => LeagueRefereeStatus::INACTIVE])->save();

        $this->setStatus('active')->assertOk()->assertJsonPath('status', 'active');
    }

    public function test_deactivating_an_already_inactive_referee_is_not_validated(): void
    {
        $this->assignedMatch();
        $this->link->forceFill(['status' => LeagueRefereeStatus::INACTIVE])->save();

        $this->setStatus('inactive')->assertOk();
    }

    public function test_the_name_is_escaped_and_the_email_is_the_fallback(): void
    {
        $this->referee->forceFill(['first_name' => '<script>x</script>', 'last_name' => ''])->save();
        $this->assignedMatch();

        $error = $this->setStatus('inactive')->assertStatus(422)->json('error');

        $this->assertStringContainsString('<b>&lt;script&gt;x&lt;/script&gt;</b>', $error);
        $this->assertStringNotContainsString('<script>', $error);

        $this->referee->forceFill(['first_name' => null, 'last_name' => null])->save();

        $this->assertStringContainsString("<b>{$this->referee->email}</b>", (string) $this->setStatus('inactive')->assertStatus(422)->json('error'));
    }
}
