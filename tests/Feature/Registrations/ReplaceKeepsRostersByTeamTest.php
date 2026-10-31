<?php

namespace Tests\Feature\Registrations;

use App\Contracts\Services\RosterServiceInterface;
use App\Core\Enum\Role as RoleEnum;
use App\Core\Enum\RosterStatus;
use App\Exceptions\CustomErrorException;
use App\Models\Player;
use App\Models\Registration;
use App\Models\Roster;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Matches\InteractsWithWalkovers;
use Tests\TestCase;

class ReplaceKeepsRostersByTeamTest extends TestCase
{
    use InteractsWithWalkovers, RefreshDatabase;

    private function registrationUrl(Tournament $tournament, Registration $registration, string $suffix): string
    {
        return "/api/leagues/{$tournament->league_id}/tournaments/{$tournament->id}/registrations/{$registration->id}/{$suffix}";
    }

    private function player(): Player
    {
        return Player::factory()->create(['birth_date' => '1995-05-05']);
    }

    private function row(Registration $registration, RosterStatus $status, int $jersey, ?Player $player = null): Roster
    {
        return Roster::factory()->forRegistration($registration)->status($status)->create([
            'player_id' => ($player ?? $this->player())->id,
            'jersey_number' => $jersey,
        ]);
    }

    /**
     * @return array{0: Team, 1: User} Equipo de reemplazo de la liga con su delegado
     */
    private function replacementWithManager(Tournament $tournament): array
    {
        $manager = $this->createManagerUser();

        return [Team::factory()->create(['league_id' => $tournament->league_id, 'manager_user_id' => $manager->id]), $manager];
    }

    public function test_the_new_team_roster_starts_empty_and_the_old_rows_keep_their_team(): void
    {
        [$league, $tournament, $header, $teams] = $this->running(4);
        $registration = $teams[0];
        $oldTeamId = $registration->team_id;
        $active = $this->row($registration, RosterStatus::ACTIVE, 7);
        $pending = $this->row($registration, RosterStatus::PENDING, 8);
        [$replacement] = $this->replacementWithManager($tournament);

        $this->getJson($this->registrationUrl($tournament, $registration, 'roster'), $header)->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('summary.total', 2);

        $this->postJson($this->registrationUrl($tournament, $registration, 'replace'), ['team_id' => $replacement->id, 'reason' => 'Entra otro equipo'], $header)->assertOk();

        $this->getJson($this->registrationUrl($tournament, $registration, 'roster'), $header)->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('summary.active', 0)
            ->assertJsonPath('summary.pending', 0)
            ->assertJsonPath('summary.total', 0)
            ->assertJsonPath('summary.by_status.released', 0);

        foreach ([$active, $pending] as $row) {
            $fresh = $row->fresh();
            $this->assertSame(RosterStatus::RELEASED, $fresh->status);
            $this->assertSame($oldTeamId, $fresh->team_id);
            $this->assertSame($registration->id, $fresh->registration_id);
        }
    }

    public function test_the_new_team_can_reuse_jerseys_and_request_released_players_and_only_sees_its_own_rows(): void
    {
        [$league, $tournament, $header, $teams] = $this->running(4);
        $tournament->forceFill(['max_roster_size' => 1])->save();
        $registration = $teams[0];
        $released = $this->player();
        $this->row($registration, RosterStatus::ACTIVE, 7, $released);
        [$replacement, $manager] = $this->replacementWithManager($tournament);

        $this->postJson($this->registrationUrl($tournament, $registration, 'replace'), ['team_id' => $replacement->id, 'reason' => 'Entra otro equipo'], $header)->assertOk();

        // El máximo (1) y el dorsal 7 ya no cuentan al jugador liberado del equipo anterior.
        $row = app(RosterServiceInterface::class)->requestPlayer($registration->refresh(), $released, 7, $manager);

        $this->assertSame(RosterStatus::PENDING, $row->status);
        $this->assertSame($replacement->id, $row->team_id);

        $list = $this->getJson($this->registrationUrl($tournament, $registration, 'roster'), $header)->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($row->id, $list->json('data.0.id'));
        $list->assertJsonPath('summary.total', 1)->assertJsonPath('summary.pending', 1);
        $this->assertSame(1, Roster::query()->where('registration_id', $registration->id)->where('status', RosterStatus::RELEASED)->count());
    }

    public function test_the_jersey_belongs_to_the_row_only_open_rows_of_the_current_team_block_it(): void
    {
        [, $tournament, , $teams] = $this->running(4);
        $manager = $this->createManagerUser();
        $teams[1]->team->forceFill(['manager_user_id' => $manager->id])->save();

        foreach ([RosterStatus::REJECTED, RosterStatus::CANCELLED, RosterStatus::REMOVED, RosterStatus::RELEASED] as $status) {
            $this->row($teams[1], $status, 10);
        }

        $row = app(RosterServiceInterface::class)->requestPlayer($teams[1], $this->player(), 10, $manager);
        $this->assertSame(RosterStatus::PENDING, $row->status);

        try {
            app(RosterServiceInterface::class)->requestPlayer($teams[1], $this->player(), 10, $manager);
            $this->fail('El dorsal repetido debió rechazarse.');
        } catch (CustomErrorException $exception) {
            $this->assertSame('Ese dorsal ya lo usa otro jugador del equipo.', $exception->getMessage());
            $this->assertSame(422, $exception->getCode());
        }
    }

    public function test_a_released_player_can_be_requested_by_another_team_with_the_same_or_another_jersey(): void
    {
        [$league, $tournament, $header, $teams] = $this->running(4);
        $manager = $this->createManagerUser();
        $teams[1]->team->forceFill(['manager_user_id' => $manager->id])->save();
        $player = $this->player();
        $original = $this->row($teams[0], RosterStatus::ACTIVE, 9, $player);
        $this->postJson($this->registrationUrl($tournament, $teams[0], 'withdraw'), ['reason' => 'Abandonó el torneo'], $header)->assertOk();

        $same = app(RosterServiceInterface::class)->requestPlayer($teams[1], $player, 9, $manager);

        $this->assertSame(9, $same->jersey_number);
        $this->assertSame($teams[1]->team_id, $same->team_id);

        $same->forceFill(['status' => RosterStatus::REJECTED])->save();
        $other = app(RosterServiceInterface::class)->requestPlayer($teams[1], $player, 21, $manager);

        $this->assertSame(21, $other->jersey_number);

        $kept = $original->fresh();
        $this->assertSame(RosterStatus::RELEASED, $kept->status);
        $this->assertSame(9, $kept->jersey_number);
        $this->assertSame($teams[0]->team_id, $kept->team_id);
    }

    public function test_a_player_removed_from_the_old_team_is_still_blocked_for_the_new_team(): void
    {
        [$league, $tournament, $header, $teams] = $this->running(4);
        $registration = $teams[0];
        $removed = $this->player();
        $this->row($registration, RosterStatus::REMOVED, 4, $removed);
        [$replacement, $manager] = $this->replacementWithManager($tournament);
        $this->postJson($this->registrationUrl($tournament, $registration, 'replace'), ['team_id' => $replacement->id, 'reason' => 'Entra otro equipo'], $header)->assertOk();

        $this->expectException(CustomErrorException::class);

        app(RosterServiceInterface::class)->requestPlayer($registration->refresh(), $removed, 4, $manager);
    }

    public function test_a_withdrawn_team_keeps_its_roster_released_with_its_team(): void
    {
        [$league, $tournament, $header, $teams] = $this->running(4);
        $registration = $teams[0];
        $this->row($registration, RosterStatus::ACTIVE, 7);

        $this->postJson($this->registrationUrl($tournament, $registration, 'withdraw'), ['reason' => 'Abandonó el torneo'], $header)->assertOk();

        $this->getJson($this->registrationUrl($tournament, $registration, 'roster'), $header)->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'released')
            ->assertJsonPath('summary.by_status.released', 1)
            ->assertJsonPath('summary.total', 1);
        $this->assertSame($registration->team_id, Roster::query()->where('registration_id', $registration->id)->firstOrFail()->team_id);
    }

    public function test_a_new_request_stores_the_team_of_the_registration(): void
    {
        [, $tournament, , $teams] = $this->running(4);
        $manager = $this->createManagerUser();
        $teams[1]->team->forceFill(['manager_user_id' => $manager->id])->save();

        $row = app(RosterServiceInterface::class)->requestPlayer($teams[1], $this->player(), 5, $manager);

        $this->assertSame($teams[1]->team_id, $row->team_id);
    }

    public function test_team_changes_lists_the_teams_a_registration_has_had(): void
    {
        [$league, $tournament, $header, $teams] = $this->running(4);
        $registration = $teams[0];
        $first = $registration->team;
        $first->forceFill(['name' => 'Primer Equipo'])->save();
        [$second] = $this->replacementWithManager($tournament);
        $second->forceFill(['name' => 'Segundo Equipo'])->save();
        [$third] = $this->replacementWithManager($tournament);
        $third->forceFill(['name' => 'Tercer Equipo'])->save();

        $this->getJson($this->registrationUrl($tournament, $registration, 'team-changes'), $header)->assertOk()->assertJsonCount(0, 'data');

        $this->postJson($this->registrationUrl($tournament, $registration, 'replace'), ['team_id' => $second->id, 'reason' => 'Primer cambio'], $header)->assertOk();
        $this->postJson($this->registrationUrl($tournament, $registration, 'replace'), ['team_id' => $third->id, 'reason' => 'Segundo cambio'], $header)->assertOk();

        $this->getJson($this->registrationUrl($tournament, $registration, 'team-changes'), $header)->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.old_team.name', 'Segundo Equipo')
            ->assertJsonPath('data.0.new_team.name', 'Tercer Equipo')
            ->assertJsonPath('data.0.reason', 'Segundo cambio')
            ->assertJsonPath('data.1.old_team.name', 'Primer Equipo')
            ->assertJsonPath('data.1.new_team.id', $second->id)
            ->assertJsonPath('data.0.changed_by.id', $league->admin_user_id);

        foreach ([RoleEnum::REFEREE, RoleEnum::PLAYER] as $role) {
            $this->app->make('auth')->forgetGuards();
            $this->getJson($this->registrationUrl($tournament, $registration, 'team-changes'), $this->headerForRole($role))->assertStatus(403);
        }
    }
}
