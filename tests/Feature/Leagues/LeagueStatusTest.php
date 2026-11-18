<?php

namespace Tests\Feature\Leagues;

use App\Core\Enum\LeagueStatus;
use App\Core\Enum\Message;
use App\Core\Enum\TournamentStatus;
use App\Models\League;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeagueStatusTest extends TestCase
{
    use InteractsWithLeagues, RefreshDatabase;

    public function test_new_league_is_active(): void
    {
        $league = League::factory()->create()->refresh();

        $this->assertSame(LeagueStatus::ACTIVE, $league->status);
    }

    public function test_superadmin_deactivates_and_reactivates_a_league(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $league = League::factory()->create();

        $this->patchJson("/api/leagues/{$league->id}/status", ['is_active' => false], $this->authHeader($token))
            ->assertOk()
            ->assertJsonPath('status', 'inactive')
            ->assertJsonPath('is_active', false);
        $this->assertSame(LeagueStatus::INACTIVE, $league->refresh()->status);

        $this->patchJson("/api/leagues/{$league->id}/status", ['is_active' => true], $this->authHeader($token))
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('is_active', true);
    }

    public function test_setting_the_same_status_responds_200(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $league = League::factory()->create();

        $this->patchJson("/api/leagues/{$league->id}/status", ['is_active' => true], $this->authHeader($token))
            ->assertOk()->assertJsonPath('status', 'active');
    }

    public function test_other_roles_get_403(): void
    {
        $admin = $this->createLeagueAdmin();
        $league = League::factory()->create(['admin_user_id' => $admin->id]);

        $this->patchJson("/api/leagues/{$league->id}/status", ['is_active' => false], $this->authHeader($admin->createToken('t')->plainTextToken))
            ->assertStatus(403);
    }

    /**
     * @return array<string, array{TournamentStatus}>
     */
    public static function blockingStatuses(): array
    {
        return ['in progress' => [TournamentStatus::IN_PROGRESS], 'playoffs' => [TournamentStatus::PLAYOFFS]];
    }

    #[DataProvider('blockingStatuses')]
    public function test_cannot_deactivate_with_running_tournaments(TournamentStatus $status): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $league = League::factory()->create();
        Tournament::factory()->status($status)->create(['league_id' => $league->id]);

        $this->patchJson("/api/leagues/{$league->id}/status", ['is_active' => false], $this->authHeader($token))
            ->assertStatus(422)
            ->assertJsonPath('error', Message::LEAGUE_DEACTIVATE_HAS_ACTIVE_TOURNAMENTS);
        $this->assertSame(LeagueStatus::ACTIVE, $league->refresh()->status);
    }

    public function test_can_deactivate_with_planning_or_finished_tournaments(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $league = League::factory()->create();
        Tournament::factory()->status(TournamentStatus::PLANNING)->create(['league_id' => $league->id]);
        Tournament::factory()->status(TournamentStatus::FINISHED)->create(['league_id' => $league->id]);

        $this->patchJson("/api/leagues/{$league->id}/status", ['is_active' => false], $this->authHeader($token))->assertOk();
    }

    public function test_requires_is_active(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $league = League::factory()->create();

        $this->patchJson("/api/leagues/{$league->id}/status", [], $this->authHeader($token))->assertStatus(422);
    }

    public function test_league_admin_is_blocked_from_an_inactive_league(): void
    {
        $admin = $this->createLeagueAdmin();
        $league = League::factory()->create(['admin_user_id' => $admin->id, 'status' => LeagueStatus::INACTIVE]);

        $this->getJson("/api/leagues/{$league->id}/fields", $this->authHeader($admin->createToken('t')->plainTextToken))
            ->assertStatus(403)
            ->assertJsonPath('error', Message::LEAGUE_INACTIVE);
    }

    public function test_superadmin_can_access_an_inactive_league(): void
    {
        $league = League::factory()->create(['status' => LeagueStatus::INACTIVE]);
        [, $superToken] = $this->actingAsSuperadmin();

        $this->getJson("/api/leagues/{$league->id}/fields", $this->authHeader($superToken))->assertOk();
    }

    public function test_my_leagues_hides_inactive_for_admin(): void
    {
        $admin = $this->createLeagueAdmin();
        League::factory()->create(['admin_user_id' => $admin->id]);
        League::factory()->create(['admin_user_id' => $admin->id, 'status' => LeagueStatus::INACTIVE]);
        $header = $this->authHeader($admin->createToken('t')->plainTextToken);

        $this->getJson('/api/my-leagues', $header)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_my_leagues_keeps_inactive_for_superadmin(): void
    {
        League::factory()->create();
        League::factory()->create(['status' => LeagueStatus::INACTIVE]);
        [, $superToken] = $this->actingAsSuperadmin();

        $response = $this->getJson('/api/my-leagues', $this->authHeader($superToken))->assertOk()->assertJsonCount(2, 'data');

        // Cada liga trae su estado: el front oculta las inactivas del selector.
        $this->assertEqualsCanonicalizing(['active', 'inactive'], $response->json('data.*.status'));
    }

    public function test_my_leagues_is_empty_when_all_are_inactive(): void
    {
        $admin = $this->createLeagueAdmin();
        League::factory()->create(['admin_user_id' => $admin->id, 'status' => LeagueStatus::INACTIVE]);

        $this->getJson('/api/my-leagues', $this->authHeader($admin->createToken('t')->plainTextToken))
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_list_filters_by_status_and_rejects_invalid_values(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        League::factory()->create();
        $inactive = League::factory()->create(['status' => LeagueStatus::INACTIVE]);

        $response = $this->getJson('/api/leagues?status=inactive', $this->authHeader($token))
            ->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($inactive->id, $response->json('data.0.id'));

        $this->getJson('/api/leagues?status=nope', $this->authHeader($token))->assertStatus(422);
    }
}
