<?php

namespace Tests\Feature\Registrations;

use App\Contracts\Services\RosterServiceInterface;
use App\Core\Enum\MatchStatus;
use App\Core\Enum\Message;
use App\Core\Enum\RegistrationStatus;
use App\Core\Enum\Role as RoleEnum;
use App\Core\Enum\RosterStatus;
use App\Core\Enum\TournamentStatus;
use App\Events\TeamWithdrawn;
use App\Models\Field;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\Registration;
use App\Models\Roster;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Matches\InteractsWithWalkovers;
use Tests\TestCase;

class WithdrawRegistrationTest extends TestCase
{
    use InteractsWithWalkovers, RefreshDatabase;

    private function withdrawUrl(Tournament $tournament, Registration $registration, string $suffix = 'withdraw'): string
    {
        return "/api/leagues/{$tournament->league_id}/tournaments/{$tournament->id}/registrations/{$registration->id}/{$suffix}";
    }

    private function roster(Registration $registration, RosterStatus $status, int $jersey): Roster
    {
        return Roster::factory()->forRegistration($registration)->status($status)->create([
            'player_id' => Player::factory()->create()->id,
            'jersey_number' => $jersey,
        ]);
    }

    /**
     * @return array{0: GameMatch, 1: list<GameMatch>} El partido ya jugado del equipo y sus partidos pendientes, estos con horario futuro
     */
    private function prepareTeam(Registration $team, Field $field, User $referee): array
    {
        $matches = GameMatch::query()
            ->where(fn ($query) => $query->where('home_registration_id', $team->id)->orWhere('away_registration_id', $team->id))
            ->orderBy('id')->get();
        $played = $matches->first();
        $played->forceFill(['status' => MatchStatus::PLAYED, 'home_score' => 2, 'away_score' => 1])->save();
        $pending = $matches->slice(1)->values();

        foreach ($pending as $index => $match) {
            $match->forceFill([
                'match_datetime' => now()->addDays(5 + $index)->setTime(10, 0),
                'field_id' => $field->id,
                'referee_user_id' => $referee->id,
                'schedule_locked' => true,
            ])->save();
        }

        return [$played, $pending->all()];
    }

    public function test_withdrawing_only_marks_the_registration_and_releases_the_roster(): void
    {
        Event::fake([TeamWithdrawn::class]);
        [$league, $tournament, $header, $teams] = $this->running(4);
        $team = $teams[3];
        $field = Field::factory()->create(['league_id' => $league->id]);
        $referee = User::factory()->create();
        [$played, $pending] = $this->prepareTeam($team, $field, $referee);
        $before = collect($pending)->map(fn (GameMatch $match) => $match->fresh()->only(['match_datetime', 'field_id', 'referee_user_id', 'schedule_locked', 'status']))->all();
        $active = $this->roster($team, RosterStatus::ACTIVE, 10);
        $waiting = $this->roster($team, RosterStatus::PENDING, 11);
        $removed = $this->roster($team, RosterStatus::REMOVED, 12);

        $this->getJson($this->withdrawUrl($tournament, $team, 'withdraw/preview'), $header)->assertOk()
            ->assertJsonPath('kept_played', 1)
            ->assertJsonPath('pending_matches', 2)
            ->assertJsonPath('players_released', 2)
            ->assertJsonMissingPath('walkovers');
        $this->assertSame(RegistrationStatus::ACTIVE, $team->fresh()->status);

        $this->postJson($this->withdrawUrl($tournament, $team), ['reason' => 'Abandonó el torneo'], $header)->assertOk()
            ->assertJsonPath('kept_played', 1)
            ->assertJsonPath('pending_matches', 2)
            ->assertJsonPath('players_released', 2)
            ->assertJsonPath('registration.status', 'withdrawn');

        $fresh = $team->fresh();
        $this->assertSame(RegistrationStatus::WITHDRAWN, $fresh->status);
        $this->assertNotNull($fresh->withdrawn_at);
        $this->assertSame('Abandonó el torneo', $fresh->withdrawn_reason);
        $this->assertNotNull($fresh->withdrawn_by_user_id);

        $this->assertSame(2, $played->fresh()->home_score);

        foreach ($pending as $index => $match) {
            $this->assertEquals($before[$index], $match->fresh()->only(['match_datetime', 'field_id', 'referee_user_id', 'schedule_locked', 'status']));
            $this->assertFalse($match->fresh()->is_walkover);
        }

        $this->assertSame(RosterStatus::RELEASED, $active->fresh()->status);
        $this->assertSame(RosterStatus::RELEASED, $waiting->fresh()->status);
        $this->assertSame(RosterStatus::REMOVED, $removed->fresh()->status);

        $rows = collect($this->getJson($this->base($league, $tournament).'/standings', $header)->assertOk()->json('groups.0.rows'))->keyBy('registration_id');
        $this->assertTrue($rows[$team->id]['withdrawn']);
        $this->assertSame(1, $rows[$team->id]['played']);

        Event::assertDispatched(TeamWithdrawn::class, fn (TeamWithdrawn $event) => $event->registration->id === $team->id);
    }

    public function test_withdrawing_and_replacing_keep_the_group_in_the_registrations_and_the_group_detail(): void
    {
        [$league, $tournament, $header, $teams] = $this->running(4);
        $group = $tournament->groups()->firstOrFail();
        $replacement = Team::factory()->create(['league_id' => $league->id]);
        $base = "/api/leagues/{$league->id}/tournaments/{$tournament->id}";

        $this->postJson($this->withdrawUrl($tournament, $teams[0]), ['reason' => 'Abandonó el torneo'], $header)->assertOk()
            ->assertJsonPath('registration.group_id', $group->id)
            ->assertJsonPath('registration.group.name', $group->name);
        $this->assertSame($group->id, $teams[0]->fresh()->group_id);

        $rows = collect($this->getJson("{$base}/registrations?per_page=50", $header)->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('withdrawn', $rows[$teams[0]->id]['status']);
        $this->assertSame($group->id, $rows[$teams[0]->id]['group_id']);
        $this->assertSame($group->name, $rows[$teams[0]->id]['group']['name']);
        $this->assertSame($group->id, $rows[$teams[1]->id]['group_id']);

        $detail = $this->getJson("{$base}/groups/{$group->id}", $header)->assertOk();
        $this->assertCount(4, $detail->json('teams'));
        $this->assertSame(3, $detail->json('teams_count'));
        $this->assertTrue(collect($detail->json('teams'))->firstWhere('id', $teams[0]->id)['withdrawn']);

        $this->postJson("{$base}/registrations/{$teams[0]->id}/replace", ['team_id' => $replacement->id, 'reason' => 'Entra otro equipo'], $header)->assertOk()
            ->assertJsonPath('registration.group_id', $group->id)
            ->assertJsonPath('registration.group.name', $group->name);

        $rows = collect($this->getJson("{$base}/registrations?per_page=50", $header)->json('data'))->keyBy('id');
        $this->assertSame('active', $rows[$teams[0]->id]['status']);
        $this->assertSame($group->id, $rows[$teams[0]->id]['group_id']);
        $this->assertSame($group->id, $teams[0]->fresh()->group_id);
        $this->assertSame(4, $this->getJson("{$base}/groups/{$group->id}", $header)->json('teams_count'));
    }

    public function test_a_pending_match_of_a_withdrawn_team_cannot_get_a_normal_result_but_accepts_a_walkover(): void
    {
        [$league, $tournament, $header, $teams] = $this->running(4);
        $this->postJson($this->withdrawUrl($tournament, $teams[3]), ['reason' => 'Abandonó el torneo'], $header)->assertOk();
        $match = GameMatch::query()->where('home_registration_id', $teams[3]->id)->orWhere('away_registration_id', $teams[3]->id)->orderBy('id')->firstOrFail();
        $teamIsHome = $match->home_registration_id === $teams[3]->id;

        $this->patchJson($this->base($league, $tournament)."/matches/{$match->id}/result", ['home_score' => 1, 'away_score' => 0], $header)->assertStatus(422)
            ->assertExactJson(['code' => 422, 'error' => 'Uno de los equipos está dado de baja. Registra victoria por default o reemplaza al equipo.']);

        $this->getJson($this->base($league, $tournament).'/matches?matchday='.$match->matchday, $header)->assertOk()
            ->assertJsonPath('data.'.collect($this->getJson($this->base($league, $tournament).'/matches?matchday='.$match->matchday, $header)->json('data'))->search(fn ($row) => $row['id'] === $match->id).'.'.($teamIsHome ? 'home_withdrawn' : 'away_withdrawn'), true);

        $this->walkover($league, $tournament, $header, $match, $teams[3]->id)->assertOk()
            ->assertJsonPath('walkover_cause', 'team_withdrawn')
            ->assertJsonPath($teamIsHome ? 'home_score' : 'away_score', 0)
            ->assertJsonPath($teamIsHome ? 'away_score' : 'home_score', 3);

        $this->deleteJson($this->base($league, $tournament)."/matches/{$match->id}/result", [], $header)->assertOk()->assertJsonPath('status', 'scheduled');
    }

    public function test_the_regular_stage_cannot_be_closed_with_pending_matches_of_a_withdrawn_team(): void
    {
        [$league, $tournament, $header, $teams] = $this->running(4);
        $group = $tournament->groups()->firstOrFail();
        $playoffId = $this->postJson($this->groupsUrl($league, $tournament), [
            'type' => 'playoff', 'name' => 'Liguilla', 'round_legs' => [1],
            'sources' => [['source_group_id' => $group->id, 'position_start' => 1, 'position_end' => 2]],
        ], $header)->assertCreated()->json('id');
        $this->playRegularStage($teams);
        $withdrawnMatch = GameMatch::query()->where('home_registration_id', $teams[3]->id)->orWhere('away_registration_id', $teams[3]->id)->firstOrFail();
        $withdrawnMatch->forceFill(['status' => MatchStatus::SCHEDULED, 'home_score' => null, 'away_score' => null])->save();
        $this->postJson($this->withdrawUrl($tournament, $teams[3]), ['reason' => 'Abandonó el torneo'], $header)->assertOk();

        $this->postJson($this->base($league, $tournament).'/close-regular-phase', ['liguillas' => [['group_id' => $playoffId, 'round_dates' => ['2026-12-07']]]], $header)
            ->assertStatus(422)
            ->assertJsonPath('error.blockers.0.type', 'withdrawn_pending_matches')
            ->assertJsonPath('error.blockers.0.message', 'Hay partidos pendientes de un equipo en baja. Registra victoria por default o reemplaza al equipo antes de cerrar la fase regular.')
            ->assertJsonPath('error.blockers.0.count', 1);

        $this->walkover($league, $tournament, $header, $withdrawnMatch, $teams[3]->id)->assertOk();
        $this->postJson($this->base($league, $tournament).'/close-regular-phase', ['liguillas' => [['group_id' => $playoffId, 'round_dates' => ['2026-12-07']]]], $header)->assertOk();
    }

    public function test_an_invalid_qualified_count_names_the_withdrawn_teams_that_would_have_qualified(): void
    {
        [$league, $tournament, $header, $teams] = $this->running(4);
        $this->playRegularStage($teams);
        $group = $tournament->groups()->firstOrFail();
        $teams[3]->team->forceFill(['name' => 'Los Retirados'])->save();
        $playoffId = $this->postJson($this->groupsUrl($league, $tournament), [
            'type' => 'playoff', 'name' => 'Liguilla', 'round_legs' => [1, 1],
            'sources' => [['source_group_id' => $group->id, 'position_start' => 1, 'position_end' => 4]],
        ], $header)->assertCreated()->json('id');
        $this->postJson($this->withdrawUrl($tournament, $teams[3]), ['reason' => 'Abandonó el torneo'], $header)->assertOk();

        $blockers = $this->getJson($this->base($league, $tournament).'/close-regular-phase/preview', $header)->assertOk()->json('blockers');

        $this->assertSame('invalid_qualified', $blockers[0]['type']);
        $this->assertSame('Hay 1 equipo(s) en baja que no clasifican, por lo que la liguilla queda con 3 equipos. Reemplaza al equipo en baja o ajusta la configuración de la liguilla para continuar.', $blockers[0]['message']);
        $this->assertSame(['Los Retirados'], $blockers[0]['withdrawn_teams']);
        $this->assertSame(3, $blockers[0]['count']);

        $this->postJson($this->base($league, $tournament).'/close-regular-phase', ['liguillas' => [['group_id' => $playoffId, 'round_dates' => ['2026-12-07', '2026-12-14']]]], $header)
            ->assertStatus(422)
            ->assertJsonPath('error.blockers.0.withdrawn_teams.0', 'Los Retirados');
    }

    public function test_without_withdrawn_teams_the_invalid_qualified_message_is_the_usual_one(): void
    {
        [$league, $tournament, $header, $teams] = $this->running(4);
        $this->playRegularStage($teams);
        $group = $tournament->groups()->firstOrFail();
        $this->postJson($this->groupsUrl($league, $tournament), [
            'type' => 'playoff', 'name' => 'Liguilla', 'round_legs' => null,
            'sources' => [['source_group_id' => $group->id, 'position_start' => 1, 'position_end' => 4]],
        ], $header)->assertCreated();
        // Sin equipos en baja: se fuerza un total que no es potencia de 2.
        DB::table('playoff_sources')->update(['position_end' => 3]);

        $blockers = $this->getJson($this->base($league, $tournament).'/close-regular-phase/preview', $header)->assertOk()->json('blockers');

        $this->assertSame(Message::CLOSE_INVALID_QUALIFIED, $blockers[0]['message']);
        $this->assertArrayNotHasKey('withdrawn_teams', $blockers[0]);
    }

    public function test_withdrawing_twice_or_outside_the_running_tournament_responds_422(): void
    {
        [$league, $tournament, $header, $teams] = $this->running(4);

        $this->postJson($this->withdrawUrl($tournament, $teams[0]), ['reason' => 'Abandonó el torneo'], $header)->assertOk();
        $this->postJson($this->withdrawUrl($tournament, $teams[0]), ['reason' => 'Abandonó el torneo'], $header)->assertStatus(422)
            ->assertJsonPath('error', Message::REGISTRATION_ALREADY_WITHDRAWN);

        foreach ([TournamentStatus::PLANNING, TournamentStatus::FINISHED] as $status) {
            $tournament->forceFill(['status' => $status])->save();

            $this->postJson($this->withdrawUrl($tournament, $teams[1]), ['reason' => 'Abandonó el torneo'], $header)->assertStatus(422)
                ->assertJsonPath('error', Message::REGISTRATION_WITHDRAW_ONLY_ACTIVE_TOURNAMENT);
        }
    }

    public function test_the_reason_is_required_between_5_and_255_characters(): void
    {
        [, $tournament, $header, $teams] = $this->running(4);

        foreach ([[], ['reason' => 'ab'], ['reason' => str_repeat('x', 256)]] as $body) {
            $this->postJson($this->withdrawUrl($tournament, $teams[0]), $body, $header)->assertStatus(422)->assertJsonStructure(['error' => ['reason']]);
        }

        $this->assertSame(RegistrationStatus::ACTIVE, $teams[0]->fresh()->status);
    }

    public function test_in_the_playoffs_the_default_is_registered_by_hand_per_match(): void
    {
        [$league, $tournament, $header, $playoffId] = $this->inPlayoffs([1, 1]);
        $semifinal = $this->tie($playoffId, 1, 1);
        $leaving = Registration::query()->findOrFail($semifinal->registration_a_id);
        [$match] = $this->tieMatches($semifinal);

        $this->postJson($this->withdrawUrl($tournament, $leaving), ['reason' => 'No puede continuar'], $header)->assertOk()
            ->assertJsonPath('pending_matches', 1);

        $this->assertNull($semifinal->fresh()->winner_registration_id);
        $this->patchJson($this->base($league, $tournament)."/matches/{$match->id}/result", ['home_score' => 1, 'away_score' => 0], $header)->assertStatus(422)
            ->assertJsonPath('error', Message::MATCH_TEAM_WITHDRAWN);

        $this->walkover($league, $tournament, $header, $match, $leaving->id)->assertOk()
            ->assertJsonPath('walkover_cause', 'team_withdrawn')
            ->assertJsonPath('tie.winner_registration_id', $semifinal->registration_b_id)
            ->assertJsonPath('tie.decided_by', 'walkover');
        $this->assertSame($semifinal->registration_b_id, $this->tie($playoffId, 2, 1)->registration_a_id);
    }

    public function test_a_withdrawn_team_does_not_qualify_and_the_next_in_the_table_takes_its_place(): void
    {
        [$league, $tournament, $header, $teams] = $this->running(4);
        $this->playRegularStage($teams);
        $group = $tournament->groups()->firstOrFail();
        $playoffId = $this->postJson($this->groupsUrl($league, $tournament), [
            'type' => 'playoff', 'name' => 'Liguilla', 'round_legs' => [1],
            'sources' => [['source_group_id' => $group->id, 'position_start' => 1, 'position_end' => 2]],
        ], $header)->assertCreated()->json('id');

        $this->postJson($this->withdrawUrl($tournament, $teams[0]), ['reason' => 'Abandonó el torneo'], $header)->assertOk();
        $this->postJson($this->base($league, $tournament).'/close-regular-phase', ['liguillas' => [['group_id' => $playoffId, 'round_dates' => ['2026-12-07']]]], $header)->assertOk();

        $final = $this->tie($playoffId, 1, 1);
        $this->assertEqualsCanonicalizing([$teams[1]->id, $teams[2]->id], [$final->registration_a_id, $final->registration_b_id]);
    }

    public function test_a_released_player_can_be_requested_by_another_team_of_the_tournament(): void
    {
        [$league, $tournament, $header, $teams] = $this->running(4);
        $manager = $this->createManagerUser();
        $other = $teams[1];
        $other->team->forceFill(['manager_user_id' => $manager->id, 'league_id' => $league->id])->save();
        $player = Player::factory()->create(['birth_date' => '1995-05-05']);
        Roster::factory()->forRegistration($teams[0])->status(RosterStatus::ACTIVE)->create(['player_id' => $player->id, 'jersey_number' => 9]);

        $this->postJson($this->withdrawUrl($tournament, $teams[0]), ['reason' => 'Abandonó el torneo'], $header)->assertOk()->assertJsonPath('players_released', 1);

        $row = app(RosterServiceInterface::class)->requestPlayer($other, $player, 9, $manager);

        $this->assertSame(RosterStatus::PENDING, $row->status);
        $this->assertSame(1, Roster::query()->where('registration_id', $teams[0]->id)->where('status', RosterStatus::RELEASED)->count());
    }

    public function test_the_preview_escapes_the_team_name(): void
    {
        [, $tournament, $header, $teams] = $this->running(4);
        $this->prepareTeam($teams[3], Field::factory()->create(['league_id' => $tournament->league_id]), User::factory()->create());
        $teams[3]->team->forceFill(['name' => '<script>x</script>'])->save();

        $warning = $this->getJson($this->withdrawUrl($tournament, $teams[3], 'withdraw/preview'), $header)->assertOk()->json('warnings.0');

        $this->assertStringContainsString('&lt;script&gt;', $warning);
        $this->assertStringNotContainsString('<script>', $warning);
    }

    public function test_permissions(): void
    {
        [$league, $tournament, $header, $teams] = $this->running(4);
        $foreign = Tournament::factory()->create();
        $foreignTeam = Registration::factory()->create(['tournament_id' => $foreign->id]);

        $this->postJson($this->withdrawUrl($foreign, $foreignTeam), ['reason' => 'Abandonó el torneo'], $header)->assertStatus(403);
        $this->postJson("/api/leagues/{$league->id}/tournaments/{$foreign->id}/registrations/{$teams[0]->id}/withdraw", ['reason' => 'Abandonó el torneo'], $header)->assertStatus(404);
        $this->app->make('auth')->forgetGuards();
        $this->postJson($this->withdrawUrl($tournament, $teams[0]), ['reason' => 'Abandonó el torneo'])->assertStatus(401);

        foreach ([RoleEnum::REFEREE, RoleEnum::PLAYER] as $role) {
            $this->postJson($this->withdrawUrl($tournament, $teams[0]), ['reason' => 'Abandonó el torneo'], $this->headerForRole($role))->assertStatus(403);
            $this->getJson($this->withdrawUrl($tournament, $teams[0], 'withdraw/preview'), $this->headerForRole($role))->assertStatus(403);
        }

        $this->assertSame(RegistrationStatus::ACTIVE, $teams[0]->fresh()->status);
    }
}
