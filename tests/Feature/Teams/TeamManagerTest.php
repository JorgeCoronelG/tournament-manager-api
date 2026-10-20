<?php

namespace Tests\Feature\Teams;

use App\Core\Enum\Message;
use App\Core\Enum\Role as RoleEnum;
use App\Models\PasswordResetToken;
use App\Models\Team;
use App\Models\User;
use App\Notifications\AccountInvitationNotification;
use App\Notifications\ManagerAssignedToTeamNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TeamManagerTest extends TestCase
{
    use InteractsWithTeams, RefreshDatabase;

    public function test_creates_a_team_with_a_new_pending_manager_and_sends_the_invitation(): void
    {
        Notification::fake();
        [$league, $header] = $this->leagueWithAdminHeader();
        $this->createRole(RoleEnum::MANAGER);

        $response = $this->postJson(
            "/api/leagues/{$league->id}/teams",
            ['name' => 'Tigres', 'manager' => $this->managerPayload(['roles' => [1]])],
            $header
        )->assertCreated()
            ->assertJsonPath('manager.first_name', 'Luis')
            ->assertJsonPath('manager.last_name', 'Hernández')
            ->assertJsonPath('manager.account_status', 'pending');

        $user = User::query()->findOrFail($response->json('manager.id'));
        $this->assertNull($user->email_verified_at);
        $this->assertSame([RoleEnum::MANAGER->value], $user->roles()->pluck('roles.id')->all());
        $this->assertDatabaseHas('teams', ['id' => $response->json('id'), 'manager_user_id' => $user->id]);
        $this->assertNotNull(PasswordResetToken::query()->find('luis@mail.test'));
        Notification::assertSentTo($user, AccountInvitationNotification::class);
        Notification::assertNotSentTo($user, ManagerAssignedToTeamNotification::class);
    }

    public function test_creates_a_team_with_an_existing_manager_by_code_notifying_an_activated_account(): void
    {
        Notification::fake();
        [$league, $header] = $this->leagueWithAdminHeader();
        $manager = $this->createManagerUser();

        $this->postJson(
            "/api/leagues/{$league->id}/teams",
            ['name' => 'Tigres', 'manager_user_code' => strtolower($manager->user_code)],
            $header
        )->assertCreated()->assertJsonPath('manager.id', $manager->id);

        Notification::assertSentTo($manager, ManagerAssignedToTeamNotification::class);
        Notification::assertNotSentTo($manager, AccountInvitationNotification::class);
    }

    public function test_a_pending_existing_manager_is_assigned_without_the_notification(): void
    {
        Notification::fake();
        [$league, $header] = $this->leagueWithAdminHeader();
        $manager = $this->createManagerUser(pending: true);

        $this->postJson("/api/leagues/{$league->id}/teams", ['name' => 'Tigres', 'manager_user_code' => $manager->user_code], $header)
            ->assertCreated();

        Notification::assertNothingSent();
    }

    public function test_a_manager_can_lead_teams_in_several_leagues(): void
    {
        Notification::fake();
        [$league, $header] = $this->leagueWithAdminHeader();
        $manager = $this->createManagerUser();
        Team::factory()->create(['manager_user_id' => $manager->id]);

        $this->postJson("/api/leagues/{$league->id}/teams", ['name' => 'Tigres', 'manager_user_code' => $manager->user_code], $header)
            ->assertCreated();
    }

    public function test_non_eligible_codes_respond_the_same_neutral_404_and_create_nothing(): void
    {
        Notification::fake();
        [$league, $header] = $this->leagueWithAdminHeader();
        $player = User::factory()->create(['user_code' => 'PLAYER01']);
        $player->roles()->attach($this->createRole(RoleEnum::PLAYER));
        $inactive = $this->createManagerUser(['is_active' => false]);
        $deleted = $this->createManagerUser();
        $deleted->delete();

        $messages = [];

        foreach (['PLAYER01', 'NOEXISTE', $inactive->user_code, $deleted->user_code] as $code) {
            $response = $this->postJson("/api/leagues/{$league->id}/teams", ['name' => 'Tigres', 'manager_user_code' => $code], $header)
                ->assertNotFound();
            $messages[] = $response->json();
        }

        $this->assertCount(1, array_unique(array_map('json_encode', $messages)));
        $this->assertDatabaseCount('teams', 0);
        $this->assertSame([RoleEnum::PLAYER->value], $player->roles()->pluck('roles.id')->all());
    }

    public function test_both_manager_forms_at_once_are_rejected(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $manager = $this->createManagerUser();

        $this->postJson(
            "/api/leagues/{$league->id}/teams",
            ['name' => 'Tigres', 'manager_user_code' => $manager->user_code, 'manager' => $this->managerPayload()],
            $header
        )->assertStatus(422)->assertJsonPath('error.manager_user_code.0', Message::TEAM_MANAGER_BOTH_FORMS);

        $this->assertDatabaseCount('teams', 0);
    }

    public function test_new_manager_with_existing_email_or_phone_is_rejected_pointing_to_the_code(): void
    {
        Notification::fake();
        [$league, $header] = $this->leagueWithAdminHeader();
        User::factory()->create(['email' => 'luis@mail.test', 'phone' => '5598765432']);
        $url = "/api/leagues/{$league->id}/teams";

        $byEmail = $this->postJson($url, ['name' => 'A', 'manager' => $this->managerPayload(['phone' => '5500000000'])], $header)
            ->assertStatus(422)->json('error');
        $byPhone = $this->postJson($url, ['name' => 'B', 'manager' => $this->managerPayload(['email' => 'otro@mail.test'])], $header)
            ->assertStatus(422)->json('error');

        $this->assertSame(Message::TEAM_MANAGER_DUPLICATE_USER, $byEmail['manager.email'][0]);
        $this->assertSame(Message::TEAM_MANAGER_DUPLICATE_USER, $byPhone['manager.phone'][0]);

        $this->assertDatabaseCount('teams', 0);
    }

    public function test_new_manager_data_is_validated(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();

        $this->postJson("/api/leagues/{$league->id}/teams", ['name' => 'A', 'manager' => ['first_name' => 'Luis']], $header)
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['manager.last_name', 'manager.email']]);
    }

    public function test_assign_changes_the_manager_without_deleting_the_previous_account(): void
    {
        Notification::fake();
        [$league, $header] = $this->leagueWithAdminHeader();
        $old = $this->createManagerUser();
        $new = $this->createManagerUser();
        $team = Team::factory()->create(['league_id' => $league->id, 'manager_user_id' => $old->id]);

        $this->putJson("/api/leagues/{$league->id}/teams/{$team->id}/manager", ['manager_user_code' => $new->user_code], $header)
            ->assertOk()->assertJsonPath('manager.id', $new->id);

        $this->assertNotNull($old->fresh());
        $this->assertSame([RoleEnum::MANAGER->value], $old->roles()->pluck('roles.id')->all());
        Notification::assertSentTo($new, ManagerAssignedToTeamNotification::class);
    }

    public function test_assign_with_new_manager_data_creates_the_user(): void
    {
        Notification::fake();
        [$league, $header] = $this->leagueWithAdminHeader();
        $this->createRole(RoleEnum::MANAGER);
        $team = Team::factory()->create(['league_id' => $league->id]);

        $id = $this->putJson("/api/leagues/{$league->id}/teams/{$team->id}/manager", ['manager' => $this->managerPayload()], $header)
            ->assertOk()->assertJsonPath('manager.account_status', 'pending')->json('manager.id');

        Notification::assertSentTo(User::query()->findOrFail($id), AccountInvitationNotification::class);
    }

    public function test_assigning_the_same_manager_again_does_not_notify(): void
    {
        Notification::fake();
        [$league, $header] = $this->leagueWithAdminHeader();
        $manager = $this->createManagerUser();
        $team = Team::factory()->create(['league_id' => $league->id, 'manager_user_id' => $manager->id]);

        $this->putJson("/api/leagues/{$league->id}/teams/{$team->id}/manager", ['manager_user_code' => $manager->user_code], $header)->assertOk();

        Notification::assertNothingSent();
    }

    public function test_assign_requires_one_of_the_forms_and_rejects_both(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $team = Team::factory()->create(['league_id' => $league->id]);
        $url = "/api/leagues/{$league->id}/teams/{$team->id}/manager";

        $this->putJson($url, [], $header)->assertStatus(422);
        $this->putJson($url, ['manager_user_code' => 'ABC', 'manager' => $this->managerPayload()], $header)->assertStatus(422);
        $this->putJson($url, ['manager_user_code' => 'NOEXISTE'], $header)->assertNotFound();
    }

    public function test_remove_manager_leaves_null_and_keeps_the_account(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $manager = $this->createManagerUser();
        $team = Team::factory()->create(['league_id' => $league->id, 'manager_user_id' => $manager->id]);

        $this->deleteJson("/api/leagues/{$league->id}/teams/{$team->id}/manager", [], $header)
            ->assertOk()->assertJsonPath('manager', null);

        $this->assertDatabaseHas('teams', ['id' => $team->id, 'manager_user_id' => null]);
        $this->assertNotNull($manager->fresh());
        $this->assertSame([RoleEnum::MANAGER->value], $manager->roles()->pluck('roles.id')->all());
    }

    public function test_edits_a_pending_manager_and_reinvites_when_the_email_changes(): void
    {
        Notification::fake();
        [$league, $header] = $this->leagueWithAdminHeader();
        $manager = $this->createManagerUser(['email' => 'viejo@mail.test'], pending: true);
        PasswordResetToken::query()->create(['email' => 'viejo@mail.test', 'code' => 'x', 'expires_at' => now()->addDay()]);
        $team = Team::factory()->create(['league_id' => $league->id, 'manager_user_id' => $manager->id]);

        $this->patchJson(
            "/api/leagues/{$league->id}/teams/{$team->id}/manager",
            $this->managerPayload(['email' => 'nuevo@mail.test']),
            $header
        )->assertOk()->assertJsonPath('manager.first_name', 'Luis')
            ->assertJsonPath('manager.last_name', 'Hernández');

        $this->assertSame('nuevo@mail.test', $manager->fresh()->email);
        $this->assertNull(PasswordResetToken::query()->find('viejo@mail.test'));
        $this->assertNotNull(PasswordResetToken::query()->find('nuevo@mail.test'));
        Notification::assertSentTo($manager, AccountInvitationNotification::class);
    }

    public function test_edit_manager_is_rejected_when_the_account_is_active(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $manager = $this->createManagerUser();
        $team = Team::factory()->create(['league_id' => $league->id, 'manager_user_id' => $manager->id]);

        $this->patchJson("/api/leagues/{$league->id}/teams/{$team->id}/manager", $this->managerPayload(), $header)
            ->assertStatus(422)
            ->assertJsonPath('error', Message::TEAM_MANAGER_EDIT_NOT_ALLOWED);
    }

    public function test_edit_manager_is_rejected_when_he_leads_teams_in_another_league(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $manager = $this->createManagerUser(pending: true);
        $team = Team::factory()->create(['league_id' => $league->id, 'manager_user_id' => $manager->id]);
        Team::factory()->create(['manager_user_id' => $manager->id]);

        $this->patchJson("/api/leagues/{$league->id}/teams/{$team->id}/manager", $this->managerPayload(), $header)
            ->assertStatus(422);
    }

    public function test_edit_manager_can_keep_his_own_email_and_phone(): void
    {
        Notification::fake();
        [$league, $header] = $this->leagueWithAdminHeader();
        $manager = $this->createManagerUser(['email' => 'luis@mail.test', 'phone' => '5598765432'], pending: true);
        $team = Team::factory()->create(['league_id' => $league->id, 'manager_user_id' => $manager->id]);

        $this->patchJson("/api/leagues/{$league->id}/teams/{$team->id}/manager", $this->managerPayload(['first_name' => 'Pedro']), $header)
            ->assertOk();

        Notification::assertNothingSent();
    }

    public function test_manager_operations_without_a_manager_respond_404(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $team = Team::factory()->create(['league_id' => $league->id]);
        $url = "/api/leagues/{$league->id}/teams/{$team->id}/manager";

        $this->patchJson($url, $this->managerPayload(), $header)->assertNotFound();
        $this->postJson("{$url}/resend-invitation", [], $header)->assertNotFound();
    }

    public function test_resend_invitation_only_for_pending_accounts(): void
    {
        Notification::fake();
        [$league, $header] = $this->leagueWithAdminHeader();
        $pending = $this->createManagerUser(pending: true);
        $active = $this->createManagerUser();
        $pendingTeam = Team::factory()->create(['league_id' => $league->id, 'manager_user_id' => $pending->id]);
        $activeTeam = Team::factory()->create(['league_id' => $league->id, 'manager_user_id' => $active->id]);

        $this->postJson("/api/leagues/{$league->id}/teams/{$pendingTeam->id}/manager/resend-invitation", [], $header)->assertOk();
        Notification::assertSentTo($pending, AccountInvitationNotification::class);

        $this->postJson("/api/leagues/{$league->id}/teams/{$activeTeam->id}/manager/resend-invitation", [], $header)
            ->assertStatus(422)->assertJsonPath('error', Message::INVITATION_ALREADY_ACTIVE);
    }

    public function test_lookup_returns_only_name_and_photo_without_contact_data(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $manager = $this->createManagerUser(['first_name' => 'Ana', 'last_name' => 'Soto', 'photo_url' => 'https://x.test/a.png']);

        $response = $this->getJson("/api/leagues/{$league->id}/teams/manager-lookup?user_code={$manager->user_code}", $header)
            ->assertOk()
            ->assertJsonPath('user_id', $manager->id)
            ->assertJsonPath('first_name', 'Ana')
            ->assertJsonPath('last_name', 'Soto')
            ->assertJsonPath('photo_url', 'https://x.test/a.png');

        $this->assertSame(['user_id', 'first_name', 'last_name', 'photo_url'], array_keys($response->json()));
    }

    public function test_lookup_responds_neutral_404_for_non_managers_and_requires_the_code(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $referee = User::factory()->create(['user_code' => 'REFEREE1']);
        $referee->roles()->attach($this->createRole(RoleEnum::REFEREE));
        $url = "/api/leagues/{$league->id}/teams/manager-lookup";

        $this->getJson("{$url}?user_code=REFEREE1", $header)->assertNotFound();
        $this->getJson("{$url}?user_code=NOEXISTE", $header)->assertNotFound();
        $this->getJson($url, $header)->assertStatus(422)->assertJsonStructure(['error' => ['user_code']]);
    }

    public function test_lookup_is_throttled(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $url = "/api/leagues/{$league->id}/teams/manager-lookup?user_code=NOEXISTE";

        for ($i = 0; $i < 20; $i++) {
            $this->getJson($url, $header)->assertNotFound();
        }

        $this->getJson($url, $header)->assertStatus(429);
    }
}
