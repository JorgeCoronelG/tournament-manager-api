<?php

namespace Tests\Feature\Rosters;

use App\Contracts\Services\RosterServiceInterface;
use App\Core\Enum\Message;
use App\Core\Enum\Role;
use App\Core\Enum\RosterStatus;
use App\Core\Enum\TournamentStatus;
use App\Models\League;
use App\Models\Player;
use App\Models\Registration;
use App\Models\Roster;
use App\Models\Tournament;
use App\Models\User;
use Database\Seeders\DevelopmentSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RosterPanelTest extends TestCase
{
    use InteractsWithRosters, RefreshDatabase;

    /**
     * @return array{0: Tournament, 1: Registration, 2: array<string, string>} Torneo, inscripción y cabecera del encargado
     */
    private function setUpPanel(array $tournament = []): array
    {
        $tournament = $this->mixedTournament($tournament);
        [$registration] = $this->registrationWithManager($tournament);
        $admin = $tournament->league->admin;

        return [$tournament, $registration, $this->authHeader($admin->createToken('t')->plainTextToken)];
    }

    private function rosterUrl(Registration $registration, string $suffix = ''): string
    {
        $tournament = Tournament::query()->findOrFail($registration->tournament_id);

        return "/api/leagues/{$tournament->league_id}/tournaments/{$tournament->id}/registrations/{$registration->id}/roster{$suffix}";
    }

    public function test_lists_the_roster_ordered_with_counts_and_filter(): void
    {
        [$tournament, $registration, $header] = $this->setUpPanel(['max_roster_size' => 15]);
        $this->rosterRow($registration, $this->player(), RosterStatus::REJECTED, 1);
        $this->rosterRow($registration, $this->player(), RosterStatus::PENDING, 2);
        $this->rosterRow($registration, $this->player(), RosterStatus::ACTIVE, 9);
        $this->rosterRow($registration, $this->player(), RosterStatus::ACTIVE, 5);
        $this->rosterRow($registration, $this->player(), RosterStatus::REMOVED, 3);

        $response = $this->getJson($this->rosterUrl($registration), $header)->assertOk();

        $this->assertSame(['active', 'active', 'pending', 'rejected', 'removed'], array_column($response->json('data'), 'status'));
        $this->assertSame([5, 9], array_slice(array_column($response->json('data'), 'jersey_number'), 0, 2));
        $response->assertJsonPath('summary', ['active' => 2, 'pending' => 1, 'max_roster_size' => 15])
            ->assertJsonStructure(['data' => [['id', 'player' => ['id', 'first_name', 'last_name', 'photo_url', 'user_code', 'gender'],
                'jersey_number', 'status', 'requested_at', 'requested_by', 'responded_at', 'removed_by', 'removed_at', 'removal_reason', 'rejoin_authorized_at']]]);

        $this->getJson($this->rosterUrl($registration, '?status=pending'), $header)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('summary.active', 2);
        $this->getJson($this->rosterUrl($registration, '?status=bogus'), $header)->assertStatus(400);
    }

    public function test_the_roster_is_paginated_searchable_and_sortable_with_a_summary_over_the_whole_roster(): void
    {
        [, $registration, $header] = $this->setUpPanel(['max_roster_size' => 20]);
        $ana = Player::factory()->create(['user_id' => User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Zúñiga', 'user_code' => 'JUG900'])->id]);
        $this->rosterRow($registration, $ana, RosterStatus::ACTIVE, 7);
        foreach (range(1, 4) as $jersey) {
            $this->rosterRow($registration, $this->player(), $jersey % 2 === 0 ? RosterStatus::PENDING : RosterStatus::ACTIVE, $jersey);
        }

        $page = $this->getJson($this->rosterUrl($registration, '?per_page=2&page=2'), $header)->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta', 'summary'])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.perPage', 2);
        $page->assertJsonPath('summary', ['active' => 3, 'pending' => 2, 'max_roster_size' => 20]);

        foreach (['zúñiga', 'jug900', 'Ana Z'] as $search) {
            $this->getJson($this->rosterUrl($registration, '?search='.urlencode($search)), $header)->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', Roster::query()->where('player_id', $ana->id)->firstOrFail()->id)
                ->assertJsonPath('summary.active', 3)
                ->assertJsonPath('summary.pending', 2);
        }

        $this->getJson($this->rosterUrl($registration, '?skip_paging=true'), $header)->assertOk()
            ->assertJsonCount(5, 'data')->assertJsonPath('meta.total', 5)->assertJsonPath('summary.active', 3);

        $this->getJson($this->rosterUrl($registration, '?sort=-jersey_number'), $header)->assertOk()
            ->assertJsonPath('data.0.jersey_number', 7);
        $this->getJson($this->rosterUrl($registration, '?sort=player_id'), $header)->assertStatus(400);
    }

    public function test_the_tournament_exposes_whether_the_playoffs_started(): void
    {
        [$tournament, , $header] = $this->setUpPanel();
        $url = "/api/leagues/{$tournament->league_id}/tournaments/{$tournament->id}";

        $this->getJson($url, $header)->assertOk()->assertJsonPath('playoffs_started', false);

        $this->startPlayoffs($tournament);

        $this->getJson($url, $header)->assertOk()->assertJsonPath('playoffs_started', true);
    }

    public function test_max_roster_size_is_null_when_the_tournament_has_no_limit(): void
    {
        [, $registration, $header] = $this->setUpPanel();

        $this->getJson($this->rosterUrl($registration), $header)->assertOk()->assertJsonPath('summary.max_roster_size', null);
    }

    public function test_another_league_admin_gets_404_and_other_roles_403(): void
    {
        [, $registration] = $this->setUpPanel();
        $row = $this->rosterRow($registration, $this->player(), RosterStatus::ACTIVE);
        $otherAdmin = $this->createLeagueAdmin();
        League::factory()->create(['admin_user_id' => $otherAdmin->id]);
        $other = $this->authHeader($otherAdmin->createToken('t')->plainTextToken);

        $this->getJson($this->rosterUrl($registration), $other)->assertForbidden();
        $this->patchJson($this->rosterUrl($registration, "/{$row->id}/remove"), ['removal_reason' => 'Motivo válido'], $other)->assertForbidden();

        foreach ([Role::MANAGER, Role::PLAYER, Role::REFEREE] as $role) {
            $header = $this->headerForRole($role);
            $this->getJson($this->rosterUrl($registration), $header)->assertForbidden();
            $this->patchJson($this->rosterUrl($registration, "/{$row->id}/remove"), ['removal_reason' => 'Motivo válido'], $header)->assertForbidden();
            $this->postJson($this->rosterUrl($registration, "/{$row->id}/rejoin"), [], $header)->assertForbidden();
        }

        $this->assertSame(RosterStatus::ACTIVE, $row->fresh()->status);
    }

    public function test_a_registration_of_another_league_is_a_neutral_404_for_the_league_admin(): void
    {
        [, , $header] = $this->setUpPanel();
        $foreign = Registration::factory()->create();
        $foreignTournament = Tournament::query()->findOrFail($foreign->tournament_id);
        $own = Tournament::query()->whereKeyNot($foreignTournament->id)->firstOrFail();

        $this->getJson("/api/leagues/{$own->league_id}/tournaments/{$foreignTournament->id}/registrations/{$foreign->id}/roster", $header)->assertNotFound();
        $this->getJson("/api/leagues/{$own->league_id}/tournaments/{$own->id}/registrations/{$foreign->id}/roster", $header)->assertNotFound();
        $this->getJson($this->rosterUrl($foreign), $header)->assertForbidden();
    }

    public function test_removes_an_active_player_with_a_reason(): void
    {
        [, $registration, $header] = $this->setUpPanel();
        $row = $this->rosterRow($registration, $this->player(), RosterStatus::ACTIVE);

        $this->patchJson($this->rosterUrl($registration, "/{$row->id}/remove"), ['removal_reason' => '  Doble inscripción '], $header)
            ->assertOk()
            ->assertJsonPath('status', 'removed')
            ->assertJsonPath('removal_reason', 'Doble inscripción')
            ->assertJsonPath('removed_by.id', Tournament::query()->firstOrFail()->league->admin_user_id);

        $this->assertNotNull($row->fresh()->removed_at);
    }

    public function test_removing_needs_a_reason_of_at_least_5_characters(): void
    {
        [, $registration, $header] = $this->setUpPanel();
        $row = $this->rosterRow($registration, $this->player(), RosterStatus::ACTIVE);
        $url = $this->rosterUrl($registration, "/{$row->id}/remove");

        $this->patchJson($url, [], $header)->assertStatus(422)->assertJsonStructure(['error' => ['removal_reason']]);
        $this->patchJson($url, ['removal_reason' => 'Mal'], $header)->assertStatus(422)->assertJsonStructure(['error' => ['removal_reason']]);
        $this->patchJson($url, ['removal_reason' => str_repeat('x', 256)], $header)->assertStatus(422);

        $this->assertSame(RosterStatus::ACTIVE, $row->fresh()->status);
    }

    public function test_only_active_rows_can_be_removed(): void
    {
        [, $registration, $header] = $this->setUpPanel();

        foreach ([RosterStatus::PENDING, RosterStatus::REJECTED, RosterStatus::CANCELLED, RosterStatus::REMOVED] as $i => $status) {
            $row = $this->rosterRow($registration, $this->player(), $status, $i + 1);

            $this->patchJson($this->rosterUrl($registration, "/{$row->id}/remove"), ['removal_reason' => 'Motivo válido'], $header)
                ->assertStatus(422)->assertJsonPath('error', Message::ROSTER_REMOVE_ONLY_ACTIVE);
        }
    }

    public function test_cannot_remove_when_the_tournament_finished_or_the_playoffs_started(): void
    {
        [$tournament, $registration, $header] = $this->setUpPanel(['status' => TournamentStatus::IN_PROGRESS]);
        $row = $this->rosterRow($registration, $this->player(), RosterStatus::ACTIVE);
        $url = $this->rosterUrl($registration, "/{$row->id}/remove");

        $this->startPlayoffs($tournament);
        $this->patchJson($url, ['removal_reason' => 'Motivo válido'], $header)
            ->assertStatus(422)->assertJsonPath('error', Message::ROSTER_REMOVE_PLAYOFFS_STARTED);

        $tournament->forceFill(['status' => TournamentStatus::FINISHED])->save();
        $this->patchJson($url, ['removal_reason' => 'Motivo válido'], $header)
            ->assertStatus(422)->assertJsonPath('error', Message::ROSTER_REMOVE_TOURNAMENT_FINISHED);

        $this->assertSame(RosterStatus::ACTIVE, $row->fresh()->status);
    }

    public function test_removing_keeps_the_attendance_and_statistics_of_the_player(): void
    {
        [$tournament, $registration, $header] = $this->setUpPanel();
        [$rival] = $this->registrationWithManager($tournament);
        $player = $this->player();
        $row = $this->rosterRow($registration, $player, RosterStatus::ACTIVE);
        $admin = $tournament->league->admin;

        $matchId = DB::table('matches')->insertGetId([
            'tournament_id' => $tournament->id, 'home_registration_id' => $registration->id, 'away_registration_id' => $rival->id,
            'phase' => 'regular_stage', 'match_datetime' => now(), 'status' => 'played', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('scans')->insert([
            'client_uuid' => (string) fake()->uuid(), 'match_id' => $matchId, 'scanned_by_user_id' => $admin->id, 'scanned_code' => 'x',
            'player_id' => $player->id, 'registration_id' => $registration->id, 'result' => 'valid', 'scanned_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('match_events')->insert([
            'client_uuid' => (string) fake()->uuid(), 'match_id' => $matchId, 'registration_id' => $registration->id, 'player_id' => $player->id,
            'type' => 'goal', 'recorded_by_user_id' => $admin->id, 'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->patchJson($this->rosterUrl($registration, "/{$row->id}/remove"), ['removal_reason' => 'Motivo válido'], $header)->assertOk();

        $this->assertSame(1, DB::table('scans')->where('player_id', $player->id)->count());
        $this->assertSame(1, DB::table('match_events')->where('player_id', $player->id)->where('is_voided', false)->count());
        $this->assertSame(1, Roster::query()->where('player_id', $player->id)->count());
    }

    public function test_authorizes_and_revokes_the_rejoin(): void
    {
        [, $registration, $header] = $this->setUpPanel();
        $row = $this->rosterRow($registration, $this->player(), RosterStatus::REMOVED);
        $url = $this->rosterUrl($registration, "/{$row->id}/rejoin");

        $this->postJson($url, [], $header)->assertOk()->assertJsonPath('rejoin_authorized_at', fn ($value) => $value !== null);
        $this->assertNotNull($row->fresh()->rejoin_authorized_at);

        $this->deleteJson($url, [], $header)->assertOk()->assertJsonPath('rejoin_authorized_at', null);
        $this->assertNull($row->fresh()->rejoin_authorized_at);

        $this->deleteJson($url, [], $header)->assertStatus(422)->assertJsonPath('error', Message::ROSTER_REJOIN_NOT_AUTHORIZED);
    }

    public function test_rejoin_can_only_be_authorized_on_removed_rows(): void
    {
        [, $registration, $header] = $this->setUpPanel();

        foreach ([RosterStatus::ACTIVE, RosterStatus::PENDING, RosterStatus::REJECTED, RosterStatus::CANCELLED] as $i => $status) {
            $row = $this->rosterRow($registration, $this->player(), $status, $i + 1);

            $this->postJson($this->rosterUrl($registration, "/{$row->id}/rejoin"), [], $header)
                ->assertStatus(422)->assertJsonPath('error', Message::ROSTER_REJOIN_ONLY_REMOVED);
        }
    }

    public function test_the_rejoin_cannot_be_revoked_once_the_player_has_a_new_request_in_the_tournament(): void
    {
        [$tournament, $registration, $header] = $this->setUpPanel();
        [$other] = $this->registrationWithManager($tournament);
        $player = $this->player();
        $row = $this->rosterRow($registration, $player, RosterStatus::REMOVED);
        $url = $this->rosterUrl($registration, "/{$row->id}/rejoin");

        $this->postJson($url, [], $header)->assertOk();
        $this->rosterRow($other, $player, RosterStatus::PENDING, 4);

        $this->deleteJson($url, [], $header)->assertStatus(422)->assertJsonPath('error', Message::ROSTER_REJOIN_ALREADY_USED);
        $this->assertNotNull($row->fresh()->rejoin_authorized_at);
    }

    public function test_a_ban_removes_active_cancels_pending_and_leaves_finished_tournaments_and_other_leagues(): void
    {
        $planning = $this->mixedTournament();
        $league = $planning->league;
        $admin = $league->admin;
        $player = $this->player();

        $inProgress = Tournament::factory()->status(TournamentStatus::IN_PROGRESS)->create(['league_id' => $league->id]);
        $finished = Tournament::factory()->status(TournamentStatus::FINISHED)->create(['league_id' => $league->id]);
        $foreign = Tournament::factory()->create();

        $rows = [];
        foreach ([[$planning, RosterStatus::ACTIVE], [$inProgress, RosterStatus::PENDING], [$finished, RosterStatus::ACTIVE], [$foreign, RosterStatus::ACTIVE]] as $i => [$tournament, $status]) {
            $rows[$i] = $this->rosterRow(Registration::factory()->create(['tournament_id' => $tournament->id]), $player, $status);
        }
        $rejected = $this->rosterRow(Registration::factory()->create(['tournament_id' => $planning->id]), $player, RosterStatus::REJECTED, 3);

        $changed = app(RosterServiceInterface::class)->releaseBannedPlayer($player, $league, $admin, ' Agresión al árbitro ');

        $this->assertSame(['removed' => 1, 'cancelled' => 1], $changed);
        $this->assertSame(RosterStatus::REMOVED, $rows[0]->fresh()->status);
        $this->assertSame('Agresión al árbitro', $rows[0]->fresh()->removal_reason);
        $this->assertSame($admin->id, $rows[0]->fresh()->removed_by_user_id);
        $this->assertNotNull($rows[0]->fresh()->removed_at);
        $this->assertSame(RosterStatus::CANCELLED, $rows[1]->fresh()->status);
        $this->assertSame(RosterStatus::ACTIVE, $rows[2]->fresh()->status);
        $this->assertSame(RosterStatus::ACTIVE, $rows[3]->fresh()->status);
        $this->assertSame(RosterStatus::REJECTED, $rejected->fresh()->status);
    }

    public function test_the_development_seeder_leaves_rosters_in_every_status_to_try_the_panel(): void
    {
        $this->seed(RolesSeeder::class);
        $this->seed(DevelopmentSeeder::class);

        $first = League::query()->orderBy('id')->firstOrFail();
        $apertura = Tournament::query()->where('league_id', $first->id)->where('name', 'Torneo Apertura')->firstOrFail();

        $this->assertSame(12, $apertura->max_roster_size);
        $statuses = Roster::query()->where('tournament_id', $apertura->id)->pluck('status')->map(fn ($s) => $s->value)->unique()->sort()->values()->all();
        $this->assertSame(['active', 'cancelled', 'pending', 'rejected', 'removed'], $statuses);
        $this->assertSame(11, Roster::query()->where('registration_id', Registration::query()->where('tournament_id', $apertura->id)->orderBy('id')->firstOrFail()->id)
            ->whereIn('status', [RosterStatus::ACTIVE, RosterStatus::PENDING])->count());
        $this->assertTrue(Roster::query()->where('tournament_id', $apertura->id)->whereNotNull('removal_reason')->whereNull('rejoin_authorized_at')->exists());
        $this->assertTrue(Roster::query()->where('tournament_id', $apertura->id)->whereNotNull('rejoin_authorized_at')->exists());

        $running = Tournament::query()->where('status', TournamentStatus::IN_PROGRESS)->firstOrFail();
        $this->assertTrue(Roster::query()->where('tournament_id', $running->id)->where('status', RosterStatus::ACTIVE)->exists());
        $this->assertSame(Player::query()->count(), 100);
        $this->assertInstanceOf(User::class, $first->admin);
    }
}
