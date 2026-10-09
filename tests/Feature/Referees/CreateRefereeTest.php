<?php

namespace Tests\Feature\Referees;

use App\Core\Enum\Message;
use App\Core\Enum\Role as RoleEnum;
use App\Models\League;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Notifications\AccountInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CreateRefereeTest extends TestCase
{
    use InteractsWithReferees, RefreshDatabase;

    public function test_creates_a_pending_referee_linked_to_the_league_and_sends_the_invitation(): void
    {
        Notification::fake();
        [$league, $header] = $this->leagueWithAdminHeader();
        $this->createRole(RoleEnum::REFEREE);

        $response = $this->postJson("/api/leagues/{$league->id}/referees", $this->payload(), $header)
            ->assertCreated()
            ->assertJsonPath('email', 'carlos@mail.test')
            ->assertJsonPath('account_status', 'pending')
            ->assertJsonPath('status', 'active');

        $this->assertSame(
            ['id', 'first_name', 'last_name', 'email', 'phone', 'user_code', 'photo_url', 'account_status', 'status', 'created_at'],
            array_keys($response->json())
        );

        $user = User::query()->findOrFail($response->json('id'));
        $this->assertNull($user->email_verified_at);
        $this->assertNotEmpty($user->user_code);
        $this->assertSame([RoleEnum::REFEREE->value], $user->roles()->pluck('roles.id')->all());
        $this->assertDatabaseHas('league_referees', ['league_id' => $league->id, 'user_id' => $user->id, 'status' => 'active']);
        $this->assertNotNull(PasswordResetToken::query()->find('carlos@mail.test'));
        Notification::assertSentTo($user, AccountInvitationNotification::class);
    }

    public function test_body_cannot_change_the_role_nor_the_league(): void
    {
        Notification::fake();
        [$league, $header] = $this->leagueWithAdminHeader();
        $other = League::factory()->create();
        $this->createRole(RoleEnum::REFEREE);
        $this->createRole(RoleEnum::SUPERADMIN);

        $id = $this->postJson("/api/leagues/{$league->id}/referees", $this->payload([
            'roles' => [RoleEnum::SUPERADMIN->value], 'role_id' => 1, 'league_id' => $other->id, 'is_active' => false,
        ]), $header)->assertCreated()->json('id');

        $user = User::query()->findOrFail($id);
        $this->assertSame([RoleEnum::REFEREE->value], $user->roles()->pluck('roles.id')->all());
        $this->assertTrue($user->is_active);
        $this->assertDatabaseHas('league_referees', ['user_id' => $id, 'league_id' => $league->id]);
        $this->assertDatabaseMissing('league_referees', ['user_id' => $id, 'league_id' => $other->id]);
    }

    public function test_phone_is_optional(): void
    {
        Notification::fake();
        [$league, $header] = $this->leagueWithAdminHeader();
        $this->createRole(RoleEnum::REFEREE);

        $this->postJson("/api/leagues/{$league->id}/referees", $this->payload(['phone' => null]), $header)
            ->assertCreated()->assertJsonPath('phone', null);
    }

    public function test_duplicate_email_or_phone_responds_422_per_field_with_the_hint(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $this->createRefereeUser(['email' => 'carlos@mail.test', 'phone' => '5512345678']);
        $url = "/api/leagues/{$league->id}/referees";

        $response = $this->postJson($url, $this->payload(), $header)->assertStatus(422);
        $this->assertSame([Message::REFEREE_DUPLICATE_USER], $response->json('error.email'));
        $this->assertSame([Message::REFEREE_DUPLICATE_USER], $response->json('error.phone'));

        $this->postJson($url, $this->payload(['phone' => '5500000000']), $header)
            ->assertStatus(422)->assertJsonStructure(['error' => ['email']])->assertJsonMissingPath('error.phone');
    }

    public function test_validates_required_fields(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();

        $this->postJson("/api/leagues/{$league->id}/referees", [], $header)
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['first_name', 'last_name', 'email']]);
        $this->postJson("/api/leagues/{$league->id}/referees", $this->payload(['phone' => '123']), $header)
            ->assertStatus(422)->assertJsonStructure(['error' => ['phone']]);
    }
}
