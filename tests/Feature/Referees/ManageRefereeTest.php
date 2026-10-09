<?php

namespace Tests\Feature\Referees;

use App\Core\Enum\LeagueRefereeStatus;
use App\Core\Enum\Message;
use App\Models\League;
use App\Models\LeagueReferee;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Notifications\AccountInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ManageRefereeTest extends TestCase
{
    use InteractsWithReferees, RefreshDatabase;

    public function test_changes_status_only_in_this_league(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $user = $this->createRefereeUser();
        $link = $this->linkReferee($league, $user);
        $otherLink = $this->linkReferee(League::factory()->create(), $user);
        $url = "/api/leagues/{$league->id}/referees/{$user->id}/status";

        $this->patchJson($url, ['status' => 'inactive'], $header)->assertOk()->assertJsonPath('status', 'inactive');
        $this->assertSame(LeagueRefereeStatus::INACTIVE, $link->fresh()->status);
        $this->assertSame(LeagueRefereeStatus::ACTIVE, $otherLink->fresh()->status);
        $this->assertTrue($user->fresh()->is_active);

        $this->patchJson($url, ['status' => 'active'], $header)->assertOk()->assertJsonPath('status', 'active');
        $this->patchJson($url, ['status' => 'banned'], $header)->assertStatus(422)->assertJsonStructure(['error' => ['status']]);
        $this->patchJson($url, [], $header)->assertStatus(422);
    }

    public function test_detach_soft_deletes_only_the_link(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $user = $this->createRefereeUser();
        $link = $this->linkReferee($league, $user);
        $otherLink = $this->linkReferee(League::factory()->create(), $user);
        $url = "/api/leagues/{$league->id}/referees/{$user->id}";

        $this->deleteJson($url, [], $header)->assertNoContent();

        $this->assertSoftDeleted('league_referees', ['id' => $link->id]);
        $this->assertNotSoftDeleted('league_referees', ['id' => $otherLink->id]);
        $this->assertNotNull(User::query()->find($user->id));
        $this->deleteJson($url, [], $header)->assertStatus(404);
    }

    public function test_user_not_linked_to_the_league_responds_404_everywhere(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $stranger = $this->createRefereeUser(pending: true);
        $this->linkReferee(League::factory()->create(), $stranger);
        $deletedLink = $this->createRefereeUser(pending: true);
        $this->linkReferee($league, $deletedLink)->delete();
        $notReferee = User::factory()->create();
        $base = "/api/leagues/{$league->id}/referees";

        foreach ([$stranger->id, $deletedLink->id, $notReferee->id, 99999] as $id) {
            $this->putJson("$base/$id", $this->payload(), $header)->assertStatus(404);
            $this->patchJson("$base/$id/status", ['status' => 'inactive'], $header)->assertStatus(404);
            $this->deleteJson("$base/$id", [], $header)->assertStatus(404);
            $this->postJson("$base/$id/resend-invitation", [], $header)->assertStatus(404);
        }
        $this->assertNotSoftDeleted('league_referees', ['user_id' => $stranger->id]);
    }

    public function test_edits_a_pending_referee_linked_only_to_this_league(): void
    {
        Notification::fake();
        [$league, $header] = $this->leagueWithAdminHeader();
        $user = $this->createRefereeUser(['email' => 'old@mail.test'], pending: true);
        $this->linkReferee($league, $user);

        $this->putJson("/api/leagues/{$league->id}/referees/{$user->id}", $this->payload(['email' => 'old@mail.test']), $header)
            ->assertOk()
            ->assertJsonPath('first_name', 'Carlos')
            ->assertJsonPath('phone', '5512345678');

        $this->assertSame('Ramírez', $user->fresh()->last_name);
        Notification::assertNothingSent();
    }

    public function test_changing_the_email_invalidates_the_old_code_and_resends_the_invitation(): void
    {
        Notification::fake();
        [$league, $header] = $this->leagueWithAdminHeader();
        $user = $this->createRefereeUser(['email' => 'old@mail.test'], pending: true);
        $this->linkReferee($league, $user);
        PasswordResetToken::query()->create([
            'email' => 'old@mail.test', 'code' => Hash::make('111111'), 'attempts' => 0, 'expires_at' => now()->addHours(72),
        ]);

        $this->putJson("/api/leagues/{$league->id}/referees/{$user->id}", $this->payload(['email' => 'new@mail.test']), $header)
            ->assertOk()->assertJsonPath('email', 'new@mail.test');

        $this->assertNull(PasswordResetToken::query()->find('old@mail.test'));
        $this->assertNotNull(PasswordResetToken::query()->find('new@mail.test'));
        Notification::assertSentTo($user->fresh(), AccountInvitationNotification::class);
    }

    public function test_edit_ignores_the_own_user_in_uniqueness_but_rejects_other_users(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $user = $this->createRefereeUser(['email' => 'carlos@mail.test', 'phone' => '5512345678'], pending: true);
        $this->linkReferee($league, $user);
        $this->createRefereeUser(['email' => 'taken@mail.test', 'phone' => '5500000001']);
        $url = "/api/leagues/{$league->id}/referees/{$user->id}";

        $this->putJson($url, $this->payload(), $header)->assertOk();
        $response = $this->putJson($url, $this->payload(['email' => 'taken@mail.test', 'phone' => '5500000001']), $header)->assertStatus(422);
        $this->assertSame([Message::REFEREE_DUPLICATE_USER], $response->json('error.email'));
        $this->assertSame([Message::REFEREE_DUPLICATE_USER], $response->json('error.phone'));
    }

    public function test_edit_is_rejected_for_an_activated_account(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $user = $this->createRefereeUser(['first_name' => 'Original']);
        $this->linkReferee($league, $user);

        $this->putJson("/api/leagues/{$league->id}/referees/{$user->id}", $this->payload(), $header)
            ->assertStatus(422)->assertJsonPath('error', Message::REFEREE_EDIT_NOT_ALLOWED);
        $this->assertSame('Original', $user->fresh()->first_name);
    }

    public function test_edit_is_rejected_when_linked_to_another_league(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $user = $this->createRefereeUser(['first_name' => 'Original'], pending: true);
        $this->linkReferee($league, $user);
        $this->linkReferee(League::factory()->create(), $user, ['status' => 'inactive']);

        $this->putJson("/api/leagues/{$league->id}/referees/{$user->id}", $this->payload(), $header)
            ->assertStatus(422)->assertJsonPath('error', Message::REFEREE_EDIT_NOT_ALLOWED);
        $this->assertSame('Original', $user->fresh()->first_name);
    }

    public function test_edit_is_allowed_when_the_other_link_was_removed(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $user = $this->createRefereeUser(pending: true);
        $this->linkReferee($league, $user);
        $this->linkReferee(League::factory()->create(), $user)->delete();

        $this->putJson("/api/leagues/{$league->id}/referees/{$user->id}", $this->payload(), $header)->assertOk();
    }

    public function test_resends_the_invitation_for_a_pending_account_only(): void
    {
        Notification::fake();
        [$league, $header] = $this->leagueWithAdminHeader();
        $pending = $this->createRefereeUser(pending: true);
        $active = $this->createRefereeUser();
        $this->linkReferee($league, $pending);
        $this->linkReferee($league, $active);

        $this->postJson("/api/leagues/{$league->id}/referees/{$pending->id}/resend-invitation", [], $header)->assertOk();
        Notification::assertSentTo($pending, AccountInvitationNotification::class);

        $this->postJson("/api/leagues/{$league->id}/referees/{$active->id}/resend-invitation", [], $header)
            ->assertStatus(422)->assertJsonPath('error', Message::INVITATION_ALREADY_ACTIVE);
    }

    public function test_resend_is_rate_limited_to_once_per_minute(): void
    {
        Notification::fake();
        [$league, $header] = $this->leagueWithAdminHeader();
        $pending = $this->createRefereeUser(pending: true);
        $this->linkReferee($league, $pending);
        $url = "/api/leagues/{$league->id}/referees/{$pending->id}/resend-invitation";

        $this->postJson($url, [], $header)->assertOk();
        $this->postJson($url, [], $header)->assertStatus(429);
    }

    public function test_detached_link_count_is_unaffected_for_other_leagues(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $this->linkReferee($league);

        $this->assertSame(1, LeagueReferee::query()->count());
        $this->getJson("/api/leagues/{$league->id}/referees", $header)->assertOk()->assertJsonCount(1, 'data');
    }
}
