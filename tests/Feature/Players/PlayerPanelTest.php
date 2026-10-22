<?php

namespace Tests\Feature\Players;

use App\Core\Enum\Message;
use App\Core\Enum\PlayerGender;
use App\Core\Enum\Role;
use App\Core\Enum\RosterStatus;
use App\Core\Enum\TournamentStatus;
use App\Models\League;
use App\Models\Player;
use App\Models\PlayerLeagueBan;
use App\Models\Registration;
use App\Models\Tournament;
use App\Models\User;
use Database\Seeders\DevelopmentSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Rosters\InteractsWithRosters;
use Tests\TestCase;

class PlayerPanelTest extends TestCase
{
    use InteractsWithRosters, RefreshDatabase;

    /**
     * @return array{0: League, 1: Registration, 2: array<string, string>} Liga, una inscripción de su torneo y la cabecera del encargado
     */
    private function leagueContext(): array
    {
        $tournament = $this->mixedTournament();
        [$registration] = $this->registrationWithManager($tournament);
        $league = $tournament->league;
        $admin = $league->admin;

        return [$league, $registration, $this->authHeader($admin->createToken('t')->plainTextToken)];
    }

    /**
     * @return array<string, string>
     */
    private function superadminHeader(): array
    {
        // El guard conserva al usuario de la petición anterior dentro de la misma prueba.
        $this->app['auth']->forgetGuards();

        return $this->authHeader($this->actingAsSuperadmin()[1]);
    }

    private function playersUrl(League $league, string $suffix = ''): string
    {
        return "/api/leagues/{$league->id}/players{$suffix}";
    }

    private function namedPlayer(string $first, string $last, string $code, PlayerGender $gender = PlayerGender::MALE): Player
    {
        $user = User::factory()->create(['first_name' => $first, 'last_name' => $last, 'user_code' => $code]);

        return Player::factory()->gender($gender)->create(['user_id' => $user->id, 'birth_date' => '1995-05-05']);
    }

    private function ban(Player $player, League $league, bool $lifted = false): PlayerLeagueBan
    {
        $ban = new PlayerLeagueBan;
        $ban->forceFill([
            'player_id' => $player->id, 'league_id' => $league->id, 'reason' => 'Motivo previo',
            'banned_by_user_id' => $league->admin_user_id, 'lifted_at' => $lifted ? now() : null,
            'lifted_by_user_id' => $lifted ? $league->admin_user_id : null,
        ])->save();

        return $ban;
    }

    public function test_the_league_admin_only_lists_players_with_a_roster_in_the_league_and_the_superadmin_all(): void
    {
        [$league, $registration, $header] = $this->leagueContext();
        $mine = $this->player();
        $this->rosterRow($registration, $mine, RosterStatus::REMOVED);
        $foreign = $this->player();
        $this->rosterRow(Registration::factory()->create(), $foreign, RosterStatus::ACTIVE);
        $nobody = $this->player();

        $response = $this->getJson($this->playersUrl($league), $header)->assertOk();

        $this->assertSame([$mine->id], array_column($response->json('data'), 'id'));
        $this->assertStringNotContainsString('email', (string) $response->getContent());
        $response->assertJsonStructure(['data' => [['id', 'first_name', 'last_name', 'photo_url', 'user_code', 'gender', 'birth_date', 'age', 'phone', 'is_banned', 'active_teams']], 'links', 'meta']);

        $all = $this->getJson($this->playersUrl($league, '?per_page=25'), $this->superadminHeader())->assertOk();
        $this->assertEqualsCanonicalizing([$mine->id, $foreign->id, $nobody->id], array_column($all->json('data'), 'id'));
    }

    public function test_lists_the_active_teams_of_the_league_age_and_banned_flag(): void
    {
        [$league, $registration, $header] = $this->leagueContext();
        $player = $this->player();
        $this->rosterRow($registration, $player, RosterStatus::ACTIVE);
        $this->rosterRow(Registration::factory()->create(), $player, RosterStatus::ACTIVE, 3);
        $this->ban($player, $league);

        $row = $this->getJson($this->playersUrl($league), $header)->assertOk()->json('data.0');

        $this->assertTrue($row['is_banned']);
        $this->assertSame(now()->diffInYears('1995-05-05', true) | 0, $row['age']);
        $this->assertCount(1, $row['active_teams']);
        $this->assertSame($registration->team->name, $row['active_teams'][0]['team']);
        $this->assertSame($registration->tournament->name, $row['active_teams'][0]['tournament']);
    }

    public function test_filters_by_gender_and_banned_and_searches_by_name_or_code(): void
    {
        [$league, $registration, $header] = $this->leagueContext();
        $ana = $this->namedPlayer('Ana', 'Zúñiga', 'JUG900', PlayerGender::FEMALE);
        $beto = $this->namedPlayer('Beto', 'Ríos', 'JUG901');
        foreach ([$ana, $beto] as $i => $player) {
            $this->rosterRow($registration, $player, RosterStatus::ACTIVE, $i + 1);
        }
        $this->ban($beto, $league);
        $ids = fn (string $query) => array_column($this->getJson($this->playersUrl($league, $query), $header)->assertOk()->json('data'), 'id');

        $this->assertSame([$ana->id], $ids('?gender=female'));
        $this->assertSame([$beto->id], $ids('?banned=true'));
        $this->assertSame([$ana->id], $ids('?banned=false'));
        $this->assertSame([$beto->id], $ids('?search=jug901'));
        $this->assertSame([$ana->id], $ids('?search='.urlencode('Ana Z')));
        $this->assertSame([$ana->id, $beto->id], $ids('?sort=name'));
        $this->assertSame([$beto->id, $ana->id], $ids('?sort=-user_code'));
        $this->getJson($this->playersUrl($league, '?sort=email'), $header)->assertStatus(400);
        $this->getJson($this->playersUrl($league, '?gender=x'), $header)->assertStatus(400);
        $this->getJson($this->playersUrl($league, '?per_page=1'), $header)->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
    }

    public function test_shows_the_detail_with_roster_history_and_bans_and_hides_players_of_other_leagues(): void
    {
        [$league, $registration, $header] = $this->leagueContext();
        $player = $this->player();
        $this->rosterRow($registration, $player, RosterStatus::REMOVED, 1)->forceFill([
            'removed_by_user_id' => $league->admin_user_id, 'removed_at' => now(), 'removal_reason' => 'Doble inscripción',
        ])->save();
        $this->ban($player, $league, true);
        $this->ban($player, $league);

        $this->getJson($this->playersUrl($league, "/{$player->id}"), $header)->assertOk()
            ->assertJsonPath('id', $player->id)
            ->assertJsonCount(1, 'rosters')
            ->assertJsonPath('rosters.0.status', 'removed')
            ->assertJsonPath('rosters.0.removal_reason', 'Doble inscripción')
            ->assertJsonPath('rosters.0.team', $registration->team->name)
            ->assertJsonCount(2, 'bans')
            ->assertJsonPath('bans.0.lifted_at', null)
            ->assertJsonPath('bans.1.lifted_by.id', $league->admin_user_id)
            ->assertJsonMissingPath('email');

        $foreign = $this->player();
        $this->rosterRow(Registration::factory()->create(), $foreign, RosterStatus::ACTIVE);

        $this->getJson($this->playersUrl($league, "/{$foreign->id}"), $header)->assertNotFound();
        $this->postJson($this->playersUrl($league, "/{$foreign->id}/ban"), ['reason' => 'Motivo válido'], $header)->assertNotFound();
        $this->postJson($this->playersUrl($league, "/{$foreign->id}/unban"), [], $header)->assertNotFound();
        $this->patchJson($this->playersUrl($league, "/{$foreign->id}/gender"), ['gender' => 'female'], $header)->assertNotFound();
        $this->getJson($this->playersUrl($league, "/{$foreign->id}"), $this->superadminHeader())->assertOk();
    }

    public function test_the_superadmin_can_correct_the_gender_of_a_player_without_roster_in_the_league(): void
    {
        [$league] = $this->leagueContext();
        $player = $this->player();

        $this->patchJson($this->playersUrl($league, "/{$player->id}/gender"), ['gender' => 'female'], $this->superadminHeader())
            ->assertOk()->assertJsonPath('gender', 'female');
    }

    public function test_banning_needs_a_reason_between_5_and_255_characters(): void
    {
        [$league, $registration, $header] = $this->leagueContext();
        $player = $this->player();
        $this->rosterRow($registration, $player, RosterStatus::ACTIVE);
        $url = $this->playersUrl($league, "/{$player->id}/ban");

        $this->postJson($url, [], $header)->assertStatus(422)->assertJsonStructure(['error' => ['reason']]);
        $this->postJson($url, ['reason' => 'Mal'], $header)->assertStatus(422)->assertJsonStructure(['error' => ['reason']]);
        $this->postJson($url, ['reason' => str_repeat('x', 256)], $header)->assertStatus(422);

        $this->assertDatabaseCount('player_league_bans', 0);
        $this->assertSame(RosterStatus::ACTIVE, $player->rosters()->firstOrFail()->status);
    }

    public function test_banning_removes_active_cancels_pending_and_keeps_finished_tournaments(): void
    {
        [$league, $registration, $header] = $this->leagueContext();
        $player = $this->player();
        $active = $this->rosterRow($registration, $player, RosterStatus::ACTIVE, 1);
        $inProgress = Tournament::factory()->status(TournamentStatus::IN_PROGRESS)->create(['league_id' => $league->id]);
        $pending = $this->rosterRow(Registration::factory()->create(['tournament_id' => $inProgress->id]), $player, RosterStatus::PENDING, 2);
        $finished = Tournament::factory()->status(TournamentStatus::FINISHED)->create(['league_id' => $league->id]);
        $finishedRow = $this->rosterRow(Registration::factory()->create(['tournament_id' => $finished->id]), $player, RosterStatus::ACTIVE, 3);

        $this->postJson($this->playersUrl($league, "/{$player->id}/ban"), ['reason' => '  Agresión al árbitro '], $header)
            ->assertOk()
            ->assertJsonPath('removed_rosters', 1)
            ->assertJsonPath('cancelled_requests', 1)
            ->assertJsonPath('player.is_banned', true);

        $this->assertDatabaseHas('player_league_bans', [
            'player_id' => $player->id, 'league_id' => $league->id, 'reason' => 'Agresión al árbitro',
            'banned_by_user_id' => $league->admin_user_id, 'lifted_at' => null,
        ]);
        $this->assertSame(RosterStatus::REMOVED, $active->fresh()->status);
        $this->assertSame('Agresión al árbitro', $active->fresh()->removal_reason);
        $this->assertSame($league->admin_user_id, $active->fresh()->removed_by_user_id);
        $this->assertNotNull($active->fresh()->removed_at);
        $this->assertSame(RosterStatus::CANCELLED, $pending->fresh()->status);
        $this->assertSame(RosterStatus::ACTIVE, $finishedRow->fresh()->status);
    }

    public function test_an_already_banned_player_cannot_be_banned_again(): void
    {
        [$league, $registration, $header] = $this->leagueContext();
        $player = $this->player();
        $this->rosterRow($registration, $player, RosterStatus::ACTIVE);
        $url = $this->playersUrl($league, "/{$player->id}/ban");

        $this->postJson($url, ['reason' => 'Primer veto'], $header)->assertOk();
        $this->postJson($url, ['reason' => 'Segundo veto'], $header)->assertStatus(422)->assertJsonPath('error', Message::PLAYER_ALREADY_BANNED);

        $this->assertDatabaseCount('player_league_bans', 1);
    }

    public function test_unbanning_lifts_the_ban_without_restoring_rosters(): void
    {
        [$league, $registration, $header] = $this->leagueContext();
        $player = $this->player();
        $row = $this->rosterRow($registration, $player, RosterStatus::ACTIVE);

        $this->postJson($this->playersUrl($league, "/{$player->id}/unban"), [], $header)
            ->assertStatus(422)->assertJsonPath('error', Message::PLAYER_NOT_BANNED);

        $this->postJson($this->playersUrl($league, "/{$player->id}/ban"), ['reason' => 'Motivo válido'], $header)->assertOk();
        $this->postJson($this->playersUrl($league, "/{$player->id}/unban"), [], $header)
            ->assertOk()->assertJsonPath('is_banned', false);

        $ban = PlayerLeagueBan::query()->firstOrFail();
        $this->assertNotNull($ban->lifted_at);
        $this->assertSame($league->admin_user_id, $ban->lifted_by_user_id);
        $this->assertSame(RosterStatus::REMOVED, $row->fresh()->status);
        $this->postJson($this->playersUrl($league, "/{$player->id}/unban"), [], $header)->assertStatus(422);
    }

    public function test_other_roles_get_403_and_another_league_admin_cannot_use_the_league(): void
    {
        [$league, $registration] = $this->leagueContext();
        $player = $this->player();
        $this->rosterRow($registration, $player, RosterStatus::ACTIVE);
        $otherAdmin = $this->createLeagueAdmin();
        League::factory()->create(['admin_user_id' => $otherAdmin->id]);
        $headers = [$this->authHeader($otherAdmin->createToken('t')->plainTextToken)];

        foreach ([Role::MANAGER, Role::PLAYER, Role::REFEREE] as $role) {
            $headers[] = $this->headerForRole($role);
        }

        foreach ($headers as $header) {
            $this->app['auth']->forgetGuards();
            $this->getJson($this->playersUrl($league), $header)->assertForbidden();
            $this->getJson($this->playersUrl($league, "/{$player->id}"), $header)->assertForbidden();
            $this->postJson($this->playersUrl($league, "/{$player->id}/ban"), ['reason' => 'Motivo válido'], $header)->assertForbidden();
            $this->postJson($this->playersUrl($league, "/{$player->id}/unban"), [], $header)->assertForbidden();
        }

        $this->assertDatabaseCount('player_league_bans', 0);
    }

    public function test_the_development_seeder_leaves_the_player_scenarios(): void
    {
        $this->seed(RolesSeeder::class);
        $this->seed(DevelopmentSeeder::class);

        $league = League::query()->orderBy('id')->firstOrFail();

        $this->assertSame(1, PlayerLeagueBan::query()->where('league_id', $league->id)->whereNull('lifted_at')->count());
        $this->assertSame(1, PlayerLeagueBan::query()->where('league_id', $league->id)->whereNotNull('lifted_at')->count());
        $this->assertTrue(Player::query()->where('gender', 'male')->whereHas('rosters', fn ($r) => $r->where('status', 'active')
            ->whereHas('tournament', fn ($t) => $t->where('gender', 'male')))->exists());
        $this->assertTrue(Player::query()->whereHas('rosters.tournament', fn ($t) => $t->where('league_id', $league->id))
            ->whereHas('rosters.tournament', fn ($t) => $t->where('league_id', '!=', $league->id))->exists());
        $this->assertTrue(User::query()->whereNull('photo_url')->whereHas('player')->exists());
        $this->assertGreaterThan(1, Player::query()->pluck('birth_date')->unique()->count());
    }
}
