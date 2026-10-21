<?php

namespace Tests\Feature\Rosters;

use App\Contracts\Services\RosterServiceInterface;
use App\Core\Enum\Message;
use App\Core\Enum\PlayerGender;
use App\Core\Enum\Role;
use App\Core\Enum\RosterStatus;
use App\Core\Enum\TournamentGender;
use App\Core\Enum\TournamentStatus;
use App\Exceptions\CustomErrorException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RosterWorkflowTest extends TestCase
{
    use InteractsWithRosters, RefreshDatabase;

    private function service(): RosterServiceInterface
    {
        return app(RosterServiceInterface::class);
    }

    private function assertRejected(callable $action, string $message, int $status = 422): void
    {
        try {
            $action();
            $this->fail('Debió rechazarse.');
        } catch (CustomErrorException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame($status, $e->getCode());
        }
    }

    public function test_the_manager_requests_a_player_and_it_starts_pending(): void
    {
        [$registration, $manager] = $this->registrationWithManager($this->mixedTournament());
        $player = $this->player();

        $roster = $this->service()->requestPlayer($registration, $player, 9, $manager);

        $this->assertSame(RosterStatus::PENDING, $roster->fresh()->status);
        $this->assertSame($manager->id, $roster->requested_by_user_id);
        $this->assertNotNull($roster->requested_at);
        $this->assertNull($roster->responded_at);
    }

    public function test_only_the_manager_of_the_team_can_request(): void
    {
        [$registration] = $this->registrationWithManager($this->mixedTournament());

        $this->assertRejected(
            fn () => $this->service()->requestPlayer($registration, $this->player(), 9, $this->createManagerUser()),
            Message::AUTHORIZATION_EXCEPTION,
            403
        );
    }

    public function test_accepting_cancels_the_other_pending_requests_of_the_player_in_the_tournament(): void
    {
        $tournament = $this->mixedTournament();
        $player = $this->player();
        [$first, $firstManager] = $this->registrationWithManager($tournament);
        [$second, $secondManager] = $this->registrationWithManager($tournament);
        [$elsewhere, $elsewhereManager] = $this->registrationWithManager($this->mixedTournament());

        $keep = $this->service()->requestPlayer($first, $player, 9, $firstManager);
        $other = $this->service()->requestPlayer($second, $player, 9, $secondManager);
        $untouched = $this->service()->requestPlayer($elsewhere, $player, 9, $elsewhereManager);

        $accepted = $this->service()->accept($keep, $player->user);

        $this->assertSame(RosterStatus::ACTIVE, $accepted->status);
        $this->assertNotNull($accepted->responded_at);
        $this->assertSame(RosterStatus::CANCELLED, $other->fresh()->status);
        $this->assertNotNull($other->fresh()->responded_at);
        $this->assertSame(RosterStatus::PENDING, $untouched->fresh()->status);
    }

    public function test_rejecting_frees_the_place_and_the_jersey(): void
    {
        [$registration, $manager] = $this->registrationWithManager($this->mixedTournament(['max_roster_size' => 1]));
        $player = $this->player();
        $roster = $this->service()->requestPlayer($registration, $player, 9, $manager);

        $this->service()->reject($roster, $player->user);

        $this->assertSame(RosterStatus::REJECTED, $roster->fresh()->status);
        $this->assertNotNull($roster->fresh()->responded_at);
        $this->service()->requestPlayer($registration, $this->player(), 9, $manager);
    }

    public function test_only_the_player_responds_and_only_while_pending(): void
    {
        [$registration, $manager] = $this->registrationWithManager($this->mixedTournament());
        $player = $this->player();
        $roster = $this->service()->requestPlayer($registration, $player, 9, $manager);

        $this->assertRejected(fn () => $this->service()->accept($roster, $manager), Message::AUTHORIZATION_EXCEPTION, 403);

        $this->service()->accept($roster, $player->user);

        $this->assertRejected(fn () => $this->service()->reject($roster, $player->user), Message::ROSTER_NOT_PENDING);
    }

    public function test_max_roster_size_counts_active_and_pending_and_closing_a_row_frees_the_place(): void
    {
        [$registration, $manager] = $this->registrationWithManager($this->mixedTournament(['max_roster_size' => 2]));
        $first = $this->player();
        $second = $this->player();

        $pending = $this->service()->requestPlayer($registration, $first, 1, $manager);
        $active = $this->service()->requestPlayer($registration, $second, 2, $manager);
        $this->service()->accept($active, $second->user);

        $this->assertRejected(fn () => $this->service()->requestPlayer($registration, $this->player(), 3, $manager), Message::ROSTER_FULL);

        $this->service()->reject($pending, $first->user);
        $this->service()->requestPlayer($registration, $this->player(), 3, $manager);

        $this->assertRejected(fn () => $this->service()->requestPlayer($registration, $this->player(), 4, $manager), Message::ROSTER_FULL);
    }

    public function test_a_cancelled_request_frees_the_place(): void
    {
        $tournament = $this->mixedTournament(['max_roster_size' => 1]);
        $player = $this->player();
        [$first, $firstManager] = $this->registrationWithManager($tournament);
        [$second, $secondManager] = $this->registrationWithManager($tournament);
        $kept = $this->service()->requestPlayer($first, $player, 1, $firstManager);
        $cancelled = $this->service()->requestPlayer($second, $player, 1, $secondManager);
        $this->service()->accept($kept, $player->user);

        $this->assertSame(RosterStatus::CANCELLED, $cancelled->fresh()->status);
        $this->service()->requestPlayer($second, $this->player(), 1, $secondManager);
    }

    public function test_a_second_open_request_for_the_same_team_and_a_used_jersey_are_rejected(): void
    {
        [$registration, $manager] = $this->registrationWithManager($this->mixedTournament());
        $player = $this->player();
        $this->service()->requestPlayer($registration, $player, 5, $manager);

        $this->assertRejected(fn () => $this->service()->requestPlayer($registration, $player, 6, $manager), Message::ROSTER_REQUEST_DUPLICATE);
        $this->assertRejected(fn () => $this->service()->requestPlayer($registration, $this->player(), 5, $manager), Message::ROSTER_JERSEY_TAKEN);
    }

    public function test_a_player_active_in_another_team_of_the_tournament_cannot_be_requested(): void
    {
        $tournament = $this->mixedTournament();
        $player = $this->player();
        [$first, $firstManager] = $this->registrationWithManager($tournament);
        [$second, $secondManager] = $this->registrationWithManager($tournament);
        $this->service()->accept($this->service()->requestPlayer($first, $player, 1, $firstManager), $player->user);

        $this->assertRejected(fn () => $this->service()->requestPlayer($second, $player, 1, $secondManager), Message::ROSTER_PLAYER_UNAVAILABLE);
    }

    public function test_a_banned_player_receives_no_requests_but_a_lifted_ban_does_not_block(): void
    {
        $tournament = $this->mixedTournament();
        [$registration, $manager] = $this->registrationWithManager($tournament);
        $player = $this->player();
        $banId = DB::table('player_league_bans')->insertGetId([
            'player_id' => $player->id, 'league_id' => $tournament->league_id, 'reason' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertRejected(fn () => $this->service()->requestPlayer($registration, $player, 1, $manager), Message::ROSTER_PLAYER_UNAVAILABLE);

        DB::table('player_league_bans')->where('id', $banId)->update(['lifted_at' => now()]);

        $this->service()->requestPlayer($registration, $player, 1, $manager);
    }

    public function test_a_ban_in_another_league_does_not_block(): void
    {
        [$registration, $manager] = $this->registrationWithManager($this->mixedTournament());
        $player = $this->player();
        DB::table('player_league_bans')->insert([
            'player_id' => $player->id, 'league_id' => $this->mixedTournament()->league_id, 'reason' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->service()->requestPlayer($registration, $player, 1, $manager);
        $this->assertDatabaseCount('rosters', 1);
    }

    public function test_gender_is_validated_against_the_tournament_with_a_neutral_message(): void
    {
        [$registration, $manager] = $this->registrationWithManager($this->mixedTournament(['gender' => TournamentGender::MALE]));

        $this->assertRejected(
            fn () => $this->service()->requestPlayer($registration, $this->player(PlayerGender::FEMALE), 1, $manager),
            Message::PLAYER_NOT_ELIGIBLE
        );
    }

    public function test_a_removed_player_cannot_join_another_team_unless_the_rejoin_is_authorized(): void
    {
        $tournament = $this->mixedTournament();
        $admin = $tournament->league->admin;
        $player = $this->player();
        [$first, $firstManager] = $this->registrationWithManager($tournament);
        [$second, $secondManager] = $this->registrationWithManager($tournament);
        $roster = $this->service()->requestPlayer($first, $player, 1, $firstManager);
        $this->service()->accept($roster, $player->user);
        $this->service()->removePlayer($roster, $admin, 'Indisciplina');

        $this->assertRejected(fn () => $this->service()->requestPlayer($second, $player, 1, $secondManager), Message::ROSTER_PLAYER_UNAVAILABLE);

        $this->service()->authorizeRejoin($roster, $admin);

        $this->assertNotNull($roster->fresh()->rejoin_authorized_at);
        $this->service()->requestPlayer($second, $player, 1, $secondManager);
    }

    public function test_removing_needs_a_reason_and_records_who_when_and_why(): void
    {
        $tournament = $this->mixedTournament();
        $admin = $tournament->league->admin;
        [$registration, $manager] = $this->registrationWithManager($tournament);
        $player = $this->player();
        $roster = $this->service()->requestPlayer($registration, $player, 1, $manager);
        $this->service()->accept($roster, $player->user);

        foreach (['', '   ', str_repeat('x', 256)] as $reason) {
            try {
                $this->service()->removePlayer($roster, $admin, $reason);
                $this->fail('Debió pedir el motivo.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('removal_reason', $e->errors());
            }
        }
        $this->assertSame(RosterStatus::ACTIVE, $roster->fresh()->status);

        $removed = $this->service()->removePlayer($roster, $admin, '  Doble inscripción ');

        $this->assertSame(RosterStatus::REMOVED, $removed->status);
        $this->assertSame($admin->id, $removed->removed_by_user_id);
        $this->assertSame('Doble inscripción', $removed->removal_reason);
        $this->assertNotNull($removed->removed_at);
    }

    public function test_superadmin_can_remove_but_manager_player_referee_and_other_league_admin_cannot(): void
    {
        $tournament = $this->mixedTournament();
        [$registration, $manager] = $this->registrationWithManager($tournament);
        $player = $this->player();
        $roster = $this->service()->requestPlayer($registration, $player, 1, $manager);
        $this->service()->accept($roster, $player->user);
        $referee = User::factory()->create();
        $referee->roles()->attach($this->createRole(Role::REFEREE));
        $otherAdmin = $this->createLeagueAdmin();

        foreach ([$manager, $player->user, $referee, $otherAdmin] as $actor) {
            $this->assertRejected(fn () => $this->service()->removePlayer($roster, $actor, 'Motivo'), Message::AUTHORIZATION_EXCEPTION, 403);
            $this->assertRejected(fn () => $this->service()->authorizeRejoin($roster, $actor), Message::AUTHORIZATION_EXCEPTION, 403);
        }
        $this->assertSame(RosterStatus::ACTIVE, $roster->fresh()->status);

        $superadmin = User::factory()->create();
        $superadmin->roles()->attach($this->createRole(Role::SUPERADMIN));

        $this->assertSame(RosterStatus::REMOVED, $this->service()->removePlayer($roster, $superadmin, 'Motivo')->status);
    }

    public function test_only_active_rows_can_be_removed_and_only_in_planning_or_in_progress(): void
    {
        $tournament = $this->mixedTournament();
        $admin = $tournament->league->admin;
        [$registration, $manager] = $this->registrationWithManager($tournament);
        $pending = $this->service()->requestPlayer($registration, $this->player(), 1, $manager);
        $active = $this->rosterRow($registration, $this->player(), RosterStatus::ACTIVE, 2);

        $this->assertRejected(fn () => $this->service()->removePlayer($pending, $admin, 'Motivo'), Message::ROSTER_REMOVE_ONLY_ACTIVE);

        $tournament->forceFill(['status' => TournamentStatus::FINISHED])->save();

        $this->assertRejected(fn () => $this->service()->removePlayer($active, $admin, 'Motivo'), Message::ROSTER_REMOVE_TOURNAMENT_FINISHED);

        $tournament->forceFill(['status' => TournamentStatus::IN_PROGRESS])->save();

        $this->assertSame(RosterStatus::REMOVED, $this->service()->removePlayer($active, $admin, 'Motivo')->status);
    }

    public function test_in_the_playoffs_no_requests_are_accepted_nor_can_pending_ones_be_accepted(): void
    {
        $tournament = $this->mixedTournament();
        [$registration, $manager] = $this->registrationWithManager($tournament);
        $player = $this->player();
        $pending = $this->service()->requestPlayer($registration, $player, 1, $manager);

        $this->startPlayoffs($tournament);

        $this->assertRejected(fn () => $this->service()->requestPlayer($registration, $this->player(), 2, $manager), Message::ROSTER_CLOSED);
        $this->assertRejected(fn () => $this->service()->accept($pending, $player->user), Message::ROSTER_CLOSED);

        $this->assertSame(1, $this->service()->closeRosters($tournament));
        $this->assertSame(RosterStatus::CANCELLED, $pending->fresh()->status);
        $this->assertNotNull($pending->fresh()->responded_at);
    }

    public function test_a_finished_tournament_closes_the_rosters(): void
    {
        [$registration, $manager] = $this->registrationWithManager($this->mixedTournament(['status' => TournamentStatus::FINISHED]));

        $this->assertRejected(fn () => $this->service()->requestPlayer($registration, $this->player(), 1, $manager), Message::ROSTER_CLOSED);
    }
}
