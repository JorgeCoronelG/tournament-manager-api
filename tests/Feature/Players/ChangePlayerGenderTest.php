<?php

namespace Tests\Feature\Players;

use App\Core\Enum\PlayerGender;
use App\Core\Enum\Role as RoleEnum;
use App\Core\Enum\RosterStatus;
use App\Core\Enum\TournamentGender;
use App\Core\Enum\TournamentStatus;
use App\Models\League;
use App\Models\Player;
use App\Models\Registration;
use App\Models\Roster;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Teams\InteractsWithTeams;
use Tests\TestCase;

class ChangePlayerGenderTest extends TestCase
{
    use InteractsWithTeams, RefreshDatabase;

    private function url(League $league, Player $player): string
    {
        return "/api/leagues/{$league->id}/players/{$player->id}/gender";
    }

    private function rosterIn(Tournament $tournament, Player $player, RosterStatus $status = RosterStatus::ACTIVE): Roster
    {
        $registration = Registration::factory()->create(['tournament_id' => $tournament->id]);

        return Roster::factory()->forRegistration($registration)->status($status)->create(['player_id' => $player->id]);
    }

    private function tournament(League $league, TournamentGender $gender, TournamentStatus $status = TournamentStatus::PLANNING, string $name = 'Copa'): Tournament
    {
        return Tournament::factory()->status($status)->create(['league_id' => $league->id, 'gender' => $gender, 'name' => $name]);
    }

    public function test_changes_the_gender_of_a_player_and_records_the_audit(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $player = Player::factory()->gender(PlayerGender::MALE)->create();
        $this->rosterIn($this->tournament($league, TournamentGender::MIXED), $player, RosterStatus::REMOVED);
        $admin = $league->admin;

        $this->patchJson($this->url($league, $player), ['gender' => 'female'], $header)
            ->assertOk()->assertJsonPath('gender', 'female')->assertJsonPath('id', $player->id);

        $this->assertSame(PlayerGender::FEMALE, $player->fresh()->gender);
        $this->assertDatabaseHas('player_gender_changes', [
            'player_id' => $player->id, 'changed_by_user_id' => $admin->id, 'old_gender' => 'male', 'new_gender' => 'female',
        ]);
    }

    public function test_the_same_gender_is_a_no_op_without_audit(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $player = Player::factory()->gender(PlayerGender::MALE)->create();
        $this->rosterIn($this->tournament($league, TournamentGender::MIXED), $player);

        $this->patchJson($this->url($league, $player), ['gender' => 'male'], $header)->assertOk();

        $this->assertDatabaseCount('player_gender_changes', 0);
    }

    public function test_an_active_roster_in_an_incompatible_open_tournament_blocks_and_names_the_tournaments(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $player = Player::factory()->gender(PlayerGender::MALE)->create();
        $this->rosterIn($this->tournament($league, TournamentGender::MALE, TournamentStatus::PLANNING, 'Copa Varonil'), $player);
        $this->rosterIn($this->tournament($league, TournamentGender::MALE, TournamentStatus::IN_PROGRESS, 'Liga Fuerte'), $player);
        $this->rosterIn($this->tournament($league, TournamentGender::MIXED, TournamentStatus::PLANNING, 'Copa Mixta'), $player);

        $this->patchJson($this->url($league, $player), ['gender' => 'female'], $header)
            ->assertStatus(422)
            ->assertJsonPath('error', 'No se puede cambiar la rama porque el jugador está inscrito en: Copa Varonil, Liga Fuerte. Primero da de baja al jugador de esos torneos.');

        $this->assertSame(PlayerGender::MALE, $player->fresh()->gender);
        $this->assertDatabaseCount('player_gender_changes', 0);
    }

    public function test_female_tournament_blocks_the_change_to_male(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $player = Player::factory()->gender(PlayerGender::FEMALE)->create();
        $this->rosterIn($this->tournament($league, TournamentGender::FEMALE), $player);

        $this->patchJson($this->url($league, $player), ['gender' => 'male'], $header)->assertStatus(422);
    }

    public function test_it_changes_once_the_player_is_removed_from_the_tournament(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $player = Player::factory()->gender(PlayerGender::MALE)->create();
        $roster = $this->rosterIn($this->tournament($league, TournamentGender::MALE), $player);
        $url = $this->url($league, $player);

        $this->patchJson($url, ['gender' => 'female'], $header)->assertStatus(422);

        $roster->forceFill(['status' => RosterStatus::REMOVED])->save();

        $this->patchJson($url, ['gender' => 'female'], $header)->assertOk();
    }

    public function test_a_player_only_in_a_mixed_tournament_can_change(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $player = Player::factory()->gender(PlayerGender::MALE)->create();
        $this->rosterIn($this->tournament($league, TournamentGender::MIXED), $player);

        $this->patchJson($this->url($league, $player), ['gender' => 'female'], $header)->assertOk();
    }

    public function test_a_finished_tournament_does_not_block(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $player = Player::factory()->gender(PlayerGender::MALE)->create();
        $this->rosterIn($this->tournament($league, TournamentGender::MALE, TournamentStatus::FINISHED), $player);

        $this->patchJson($this->url($league, $player), ['gender' => 'female'], $header)->assertOk();
    }

    public function test_a_player_without_roster_in_the_league_is_404(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $stranger = Player::factory()->create();
        $inOtherLeague = Player::factory()->create();
        $this->rosterIn(Tournament::factory()->create(), $inOtherLeague);

        $this->patchJson($this->url($league, $stranger), ['gender' => 'female'], $header)->assertNotFound();
        $this->patchJson($this->url($league, $inOtherLeague), ['gender' => 'female'], $header)->assertNotFound();
        $this->patchJson("/api/leagues/{$league->id}/players/999999/gender", ['gender' => 'female'], $header)->assertNotFound();
    }

    public function test_superadmin_can_change_it(): void
    {
        $tournament = Tournament::factory()->create();
        $player = Player::factory()->gender(PlayerGender::MALE)->create();
        $this->rosterIn($tournament, $player);

        $this->patchJson($this->url($tournament->league, $player), ['gender' => 'female'], $this->authHeader($this->actingAsSuperadmin()[1]))->assertOk();
    }

    public function test_validates_the_gender(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $player = Player::factory()->create();
        $this->rosterIn($this->tournament($league, TournamentGender::MIXED), $player);

        foreach ([[], ['gender' => 'mixed'], ['gender' => 'x'], ['gender' => '']] as $body) {
            $this->patchJson($this->url($league, $player), $body, $header)->assertStatus(422)->assertJsonStructure(['error' => ['gender']]);
        }
    }

    public function test_other_roles_and_the_admin_of_another_league_have_no_access(): void
    {
        [$league] = $this->leagueWithAdminHeader();
        $player = Player::factory()->create();
        $this->rosterIn($this->tournament($league, TournamentGender::MIXED), $player);
        $url = $this->url($league, $player);

        $this->patchJson($url, ['gender' => 'male'])->assertUnauthorized();
        foreach ([RoleEnum::MANAGER, RoleEnum::REFEREE, RoleEnum::PLAYER] as $role) {
            $this->patchJson($url, ['gender' => 'male'], $this->headerForRole($role))->assertForbidden();
        }
        [, $otherHeader] = $this->leagueWithAdminHeader();
        $this->patchJson($url, ['gender' => 'male'], $otherHeader)->assertForbidden();
    }
}
