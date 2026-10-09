<?php

namespace Tests\Feature\Referees;

use App\Core\Enum\LeagueRefereeStatus;
use App\Core\Enum\Message;
use App\Core\Enum\Role as RoleEnum;
use App\Models\LeagueReferee;
use App\Models\User;
use App\Notifications\RefereeAddedToLeagueNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class LookupAndAttachRefereeTest extends TestCase
{
    use InteractsWithReferees, RefreshDatabase;

    public function test_lookup_returns_only_the_minimum_data_case_insensitively(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $referee = $this->createRefereeUser(['user_code' => 'ABCD2345', 'first_name' => 'Luis', 'last_name' => 'Soto']);

        $response = $this->getJson("/api/leagues/{$league->id}/referees/lookup?user_code=abcd2345", $header)
            ->assertOk()
            ->assertExactJson([
                'user_id' => $referee->id, 'first_name' => 'Luis', 'last_name' => 'Soto',
                'photo_url' => $referee->photo_url, 'already_in_league' => false,
            ]);
        $this->assertArrayNotHasKey('email', $response->json());
        $this->assertArrayNotHasKey('phone', $response->json());
    }

    public function test_lookup_reports_already_in_league_only_for_an_active_link(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $active = $this->createRefereeUser();
        $inactive = $this->createRefereeUser();
        $this->linkReferee($league, $active);
        $this->linkReferee($league, $inactive, ['status' => 'inactive']);
        $url = "/api/leagues/{$league->id}/referees/lookup?user_code=";

        $this->getJson($url.$active->user_code, $header)->assertJsonPath('already_in_league', true);
        $this->getJson($url.$inactive->user_code, $header)->assertJsonPath('already_in_league', false);
    }

    public function test_lookup_and_attach_respond_the_same_404_for_every_ineligible_code(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $notReferee = User::factory()->create(['user_code' => 'PLAYER01']);
        $notReferee->roles()->attach($this->createRole(RoleEnum::PLAYER));
        $inactive = $this->createRefereeUser(['is_active' => false, 'user_code' => 'INACT001']);
        $deleted = $this->createRefereeUser(['user_code' => 'DELETED1']);
        $deleted->delete();

        $responses = [];
        foreach (['NOEXISTE', 'PLAYER01', 'INACT001', 'DELETED1'] as $code) {
            $responses[] = $this->getJson("/api/leagues/{$league->id}/referees/lookup?user_code={$code}", $header)
                ->assertStatus(404)->json();
            $responses[] = $this->postJson("/api/leagues/{$league->id}/referees/attach", ['user_code' => $code], $header)
                ->assertStatus(404)->json();
        }

        $this->assertCount(1, array_unique(array_map('json_encode', $responses)));
        $this->assertSame(Message::MODEL_NOT_FOUND_EXCEPTION, $responses[0]['error']);
        $this->assertSame(0, LeagueReferee::query()->count());
    }

    public function test_user_code_is_required(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();

        $this->getJson("/api/leagues/{$league->id}/referees/lookup", $header)->assertStatus(422);
        $this->postJson("/api/leagues/{$league->id}/referees/attach", [], $header)->assertStatus(422);
    }

    public function test_attach_creates_the_link_and_notifies_an_activated_account(): void
    {
        Notification::fake();
        [$league, $header] = $this->leagueWithAdminHeader();
        $referee = $this->createRefereeUser();

        $this->postJson("/api/leagues/{$league->id}/referees/attach", ['user_code' => strtolower($referee->user_code)], $header)
            ->assertOk()
            ->assertJsonPath('id', $referee->id)
            ->assertJsonPath('status', 'active');

        $this->assertDatabaseHas('league_referees', ['league_id' => $league->id, 'user_id' => $referee->id, 'status' => 'active']);
        Notification::assertSentTo($referee, RefereeAddedToLeagueNotification::class);
    }

    public function test_attach_does_not_notify_a_pending_account(): void
    {
        Notification::fake();
        [$league, $header] = $this->leagueWithAdminHeader();
        $referee = $this->createRefereeUser(pending: true);

        $this->postJson("/api/leagues/{$league->id}/referees/attach", ['user_code' => $referee->user_code], $header)->assertOk();

        $this->assertDatabaseHas('league_referees', ['league_id' => $league->id, 'user_id' => $referee->id]);
        Notification::assertNothingSent();
    }

    public function test_attach_rejects_an_already_active_referee(): void
    {
        Notification::fake();
        [$league, $header] = $this->leagueWithAdminHeader();
        $referee = $this->createRefereeUser();
        $this->linkReferee($league, $referee);

        $this->postJson("/api/leagues/{$league->id}/referees/attach", ['user_code' => $referee->user_code], $header)
            ->assertStatus(422)->assertJsonPath('error', Message::REFEREE_ALREADY_IN_LEAGUE);
        Notification::assertNothingSent();
    }

    public function test_attach_reactivates_an_inactive_link(): void
    {
        Notification::fake();
        [$league, $header] = $this->leagueWithAdminHeader();
        $referee = $this->createRefereeUser();
        $link = $this->linkReferee($league, $referee, ['status' => 'inactive']);

        $this->postJson("/api/leagues/{$league->id}/referees/attach", ['user_code' => $referee->user_code], $header)->assertOk();

        $this->assertSame(LeagueRefereeStatus::ACTIVE, $link->fresh()->status);
        $this->assertSame(1, LeagueReferee::withTrashed()->count());
        Notification::assertSentTo($referee, RefereeAddedToLeagueNotification::class);
    }

    public function test_attach_restores_a_deleted_link(): void
    {
        Notification::fake();
        [$league, $header] = $this->leagueWithAdminHeader();
        $referee = $this->createRefereeUser();
        $link = $this->linkReferee($league, $referee, ['status' => 'inactive']);
        $link->delete();

        $this->postJson("/api/leagues/{$league->id}/referees/attach", ['user_code' => $referee->user_code], $header)->assertOk();

        $fresh = LeagueReferee::query()->findOrFail($link->id);
        $this->assertSame(LeagueRefereeStatus::ACTIVE, $fresh->status);
        $this->assertSame(1, LeagueReferee::withTrashed()->count());
    }

    public function test_lookup_is_rate_limited_per_user(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $url = "/api/leagues/{$league->id}/referees/lookup?user_code=NOEXISTE";

        for ($i = 0; $i < 20; $i++) {
            $this->getJson($url, $header)->assertStatus(404);
        }

        $this->getJson($url, $header)->assertStatus(429);
    }
}
