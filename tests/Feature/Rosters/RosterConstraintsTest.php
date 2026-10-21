<?php

namespace Tests\Feature\Rosters;

use App\Core\Enum\RosterStatus;
use App\Models\Roster;
use App\Models\Tournament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RosterConstraintsTest extends TestCase
{
    use InteractsWithRosters, RefreshDatabase;

    public function test_pending_requests_of_the_same_player_in_different_tournaments_are_allowed(): void
    {
        $player = $this->player();

        foreach ([$this->mixedTournament(), $this->mixedTournament()] as $tournament) {
            [$registration] = $this->registrationWithManager($tournament);
            $this->rosterRow($registration, $player, RosterStatus::PENDING);
        }

        $this->assertDatabaseCount('rosters', 2);
    }

    public function test_pending_requests_of_the_same_player_in_the_same_tournament_with_different_teams_are_allowed(): void
    {
        $tournament = $this->mixedTournament();
        $player = $this->player();

        foreach (range(1, 2) as $ignored) {
            [$registration] = $this->registrationWithManager($tournament);
            $this->rosterRow($registration, $player, RosterStatus::PENDING);
        }

        $this->assertDatabaseCount('rosters', 2);
    }

    public function test_two_active_rows_of_the_same_player_in_the_same_tournament_are_rejected_by_the_database(): void
    {
        $tournament = $this->mixedTournament();
        $player = $this->player();
        [$first] = $this->registrationWithManager($tournament);
        [$second] = $this->registrationWithManager($tournament);
        $this->rosterRow($first, $player, RosterStatus::ACTIVE);

        $this->expectException(QueryException::class);
        $this->rosterRow($second, $player, RosterStatus::ACTIVE);
    }

    public function test_an_active_row_does_not_conflict_with_the_closed_rows_of_the_same_player(): void
    {
        $tournament = $this->mixedTournament();
        $player = $this->player();
        [$first] = $this->registrationWithManager($tournament);
        [$second] = $this->registrationWithManager($tournament);
        $this->rosterRow($first, $player, RosterStatus::ACTIVE);

        foreach ([RosterStatus::REJECTED, RosterStatus::CANCELLED, RosterStatus::REMOVED] as $status) {
            $this->rosterRow($second, $player, $status, 20 + count(RosterStatus::cases()));
        }

        $this->assertDatabaseCount('rosters', 4);
    }

    /**
     * @return array<string, array{0: RosterStatus, 1: RosterStatus}>
     */
    public static function openPairs(): array
    {
        return [
            'pending then active' => [RosterStatus::PENDING, RosterStatus::ACTIVE],
            'active then pending' => [RosterStatus::ACTIVE, RosterStatus::PENDING],
            'pending twice' => [RosterStatus::PENDING, RosterStatus::PENDING],
        ];
    }

    #[DataProvider('openPairs')]
    public function test_the_same_jersey_in_a_team_is_rejected_while_both_rows_are_open(RosterStatus $first, RosterStatus $second): void
    {
        [$registration] = $this->registrationWithManager($this->mixedTournament());
        $this->rosterRow($registration, $this->player(), $first, 7);

        $this->expectException(QueryException::class);
        $this->rosterRow($registration, $this->player(), $second, 7);
    }

    /**
     * @return array<string, array{0: RosterStatus}>
     */
    public static function closedStatuses(): array
    {
        return [
            'rejected' => [RosterStatus::REJECTED],
            'cancelled' => [RosterStatus::CANCELLED],
            'removed' => [RosterStatus::REMOVED],
        ];
    }

    #[DataProvider('closedStatuses')]
    public function test_the_jersey_is_free_again_once_the_other_row_is_closed(RosterStatus $closed): void
    {
        [$registration] = $this->registrationWithManager($this->mixedTournament());
        $this->rosterRow($registration, $this->player(), $closed, 7);

        $this->rosterRow($registration, $this->player(), RosterStatus::ACTIVE, 7);
        $this->rosterRow($registration, $this->player(), $closed, 7);

        $this->assertDatabaseCount('rosters', 3);
    }

    public function test_the_same_jersey_in_another_team_is_fine(): void
    {
        $tournament = $this->mixedTournament();
        [$first] = $this->registrationWithManager($tournament);
        [$second] = $this->registrationWithManager($tournament);

        $this->rosterRow($first, $this->player(), RosterStatus::ACTIVE, 7);
        $this->rosterRow($second, $this->player(), RosterStatus::ACTIVE, 7);

        $this->assertDatabaseCount('rosters', 2);
    }

    public function test_a_roster_cannot_point_to_a_registration_of_another_tournament(): void
    {
        [$registration] = $this->registrationWithManager($this->mixedTournament());
        $other = Tournament::factory()->create();

        $this->expectException(QueryException::class);
        Roster::factory()->state(['registration_id' => $registration->id, 'tournament_id' => $other->id])->create();
    }

    public function test_scan_results_by_roster_status(): void
    {
        $this->assertSame('valid', RosterStatus::ACTIVE->scanResult()->value);
        $this->assertSame('removed', RosterStatus::REMOVED->scanResult()->value);

        foreach ([RosterStatus::PENDING, RosterStatus::REJECTED, RosterStatus::CANCELLED] as $status) {
            $this->assertSame('not_registered', $status->scanResult()->value, $status->value);
        }
    }
}
