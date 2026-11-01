<?php

namespace Tests\Feature\Rosters;

use App\Core\Enum\Message;
use App\Core\Enum\PlayerGender;
use App\Core\Enum\Role as RoleEnum;
use App\Core\Enum\RosterStatus;
use App\Core\Enum\TournamentGender;
use App\Core\Enum\TournamentStatus;
use App\Models\Player;
use App\Models\Registration;
use App\Models\User;
use App\Notifications\RosterRequestReceivedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Endpoints de la app móvil para armar la plantilla: el delegado busca por clave y manda la solicitud; el
 * jugador la ve y la acepta o la rechaza.
 */
class MobileRosterRequestsTest extends TestCase
{
    use InteractsWithRosters, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    /**
     * @return array<string, string>
     */
    private function headerFor(User $user): array
    {
        return $this->authHeader($user->createToken('t')->plainTextToken) + ['Accept' => 'application/json'];
    }

    private function playerWithCode(string $code, PlayerGender $gender = PlayerGender::MALE): Player
    {
        $player = $this->player($gender);
        $user = $player->user;
        assert($user !== null);
        $user->forceFill(['user_code' => $code])->save();
        $user->roles()->attach($this->createRole(RoleEnum::PLAYER));

        return $player->fresh(['user']) ?? $player;
    }

    private function rosterUrl(Registration $registration, string $suffix = ''): string
    {
        return "/api/manager/registrations/{$registration->id}/roster{$suffix}";
    }

    public function test_the_manager_finds_a_player_by_exact_code_and_sees_only_name_and_photo(): void
    {
        [, $manager] = $this->registrationWithManager($this->mixedTournament());
        $player = $this->playerWithCode('ABCD2345');

        $response = $this->getJson('/api/manager/player-lookup?user_code=abcd2345', $this->headerFor($manager))
            ->assertOk()
            ->assertJsonPath('player_id', $player->id)
            ->assertJsonPath('first_name', $player->user?->first_name);

        $this->assertSame(['player_id', 'first_name', 'last_name', 'photo_url'], array_keys($response->json()));

        $this->getJson('/api/manager/player-lookup?user_code=AB', $this->headerFor($manager))->assertStatus(422);
        $this->getJson('/api/manager/player-lookup?user_code=ZZZZ9999', $this->headerFor($manager))
            ->assertNotFound()->assertJsonPath('error', Message::PLAYER_NOT_FOUND);
    }

    public function test_the_manager_sends_a_request_and_the_player_gets_an_email(): void
    {
        [$registration, $manager] = $this->registrationWithManager($this->mixedTournament());
        $player = $this->playerWithCode('ABCD2345');

        $this->postJson($this->rosterUrl($registration), ['user_code' => 'ABCD2345', 'jersey_number' => 10], $this->headerFor($manager))
            ->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('jersey_number', 10)
            ->assertJsonPath('player.id', $player->id);

        Notification::assertSentTo($player->user, RosterRequestReceivedNotification::class);
    }

    public function test_business_rules_come_back_as_422_with_the_message(): void
    {
        [$registration, $manager] = $this->registrationWithManager($this->mixedTournament(['gender' => TournamentGender::FEMALE]));
        $this->playerWithCode('ABCD2345', PlayerGender::MALE);
        $taken = $this->playerWithCode('EFGH6789', PlayerGender::FEMALE);
        $this->rosterRow($registration, $taken, RosterStatus::ACTIVE, 7);
        $this->playerWithCode('JKMN2345', PlayerGender::FEMALE);

        $this->postJson($this->rosterUrl($registration), ['user_code' => 'ABCD2345', 'jersey_number' => 10], $this->headerFor($manager))
            ->assertStatus(422)->assertJsonPath('error', Message::PLAYER_NOT_ELIGIBLE);
        $this->postJson($this->rosterUrl($registration), ['user_code' => 'JKMN2345', 'jersey_number' => 7], $this->headerFor($manager))
            ->assertStatus(422)->assertJsonPath('error', Message::ROSTER_JERSEY_TAKEN);

        Notification::assertNothingSent();
    }

    public function test_only_the_manager_of_the_team_sees_its_roster_and_sends_requests(): void
    {
        [$registration] = $this->registrationWithManager($this->mixedTournament());
        $this->playerWithCode('ABCD2345');
        $stranger = $this->createManagerUser();

        $this->getJson($this->rosterUrl($registration), $this->headerFor($stranger))->assertForbidden();
        $this->postJson($this->rosterUrl($registration), ['user_code' => 'ABCD2345', 'jersey_number' => 10], $this->headerFor($stranger))
            ->assertForbidden();

        $playerOnly = $this->playerWithCode('EFGH6789')->user;
        assert($playerOnly !== null);
        $this->getJson('/api/manager/registrations', $this->headerFor($playerOnly))->assertForbidden();
    }

    public function test_the_manager_lists_his_registrations_and_roster(): void
    {
        $tournament = $this->mixedTournament(['max_roster_size' => 15]);
        [$registration, $manager] = $this->registrationWithManager($tournament);
        $this->rosterRow($registration, $this->playerWithCode('ABCD2345'), RosterStatus::ACTIVE, 9);
        $this->rosterRow($registration, $this->playerWithCode('EFGH6789'), RosterStatus::PENDING, 4);
        $this->rosterRow($registration, $this->playerWithCode('JKMN2345'), RosterStatus::REJECTED, 4);
        $this->registrationWithManager($tournament);

        $this->getJson('/api/manager/registrations', $this->headerFor($manager))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $registration->id)
            ->assertJsonPath('data.0.tournament.max_roster_size', 15)
            ->assertJsonPath('data.0.rosters_open', true)
            ->assertJsonPath('data.0.active_count', 1)
            ->assertJsonPath('data.0.pending_count', 1);

        $this->getJson($this->rosterUrl($registration), $this->headerFor($manager))
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.1.status', 'pending')
            ->assertJsonPath('data.2.status', 'rejected')
            ->assertJsonMissingPath('data.0.player.gender');
    }

    public function test_rosters_closed_when_the_tournament_finished(): void
    {
        [$registration, $manager] = $this->registrationWithManager($this->mixedTournament(['status' => TournamentStatus::FINISHED]));
        $this->playerWithCode('ABCD2345');

        $this->getJson('/api/manager/registrations', $this->headerFor($manager))->assertJsonPath('data.0.rosters_open', false);
        $this->postJson($this->rosterUrl($registration), ['user_code' => 'ABCD2345', 'jersey_number' => 10], $this->headerFor($manager))
            ->assertStatus(422)->assertJsonPath('error', Message::ROSTER_CLOSED);
    }

    public function test_the_manager_cancels_a_pending_request_but_not_an_accepted_player(): void
    {
        [$registration, $manager] = $this->registrationWithManager($this->mixedTournament());
        $pending = $this->rosterRow($registration, $this->playerWithCode('ABCD2345'), RosterStatus::PENDING, 9);
        $active = $this->rosterRow($registration, $this->playerWithCode('EFGH6789'), RosterStatus::ACTIVE, 4);

        $this->patchJson($this->rosterUrl($registration, "/{$pending->id}/cancel"), [], $this->headerFor($manager))
            ->assertOk()->assertJsonPath('status', 'cancelled');
        $this->patchJson($this->rosterUrl($registration, "/{$active->id}/cancel"), [], $this->headerFor($manager))
            ->assertStatus(422)->assertJsonPath('error', Message::ROSTER_NOT_PENDING);
        $this->patchJson($this->rosterUrl($registration, "/{$pending->id}/cancel"), [], $this->headerFor($this->createManagerUser()))
            ->assertForbidden();

        $this->assertSame(RosterStatus::ACTIVE, $active->fresh()?->status);
    }

    public function test_the_player_sees_and_accepts_a_request_and_the_others_of_the_tournament_are_cancelled(): void
    {
        $tournament = $this->mixedTournament();
        $player = $this->playerWithCode('ABCD2345');
        [$first] = $this->registrationWithManager($tournament);
        [$second] = $this->registrationWithManager($tournament);
        $keep = $this->rosterRow($first, $player, RosterStatus::PENDING, 9);
        $other = $this->rosterRow($second, $player, RosterStatus::PENDING, 9);
        $user = $player->user;
        assert($user !== null);

        $this->getJson('/api/player/roster-requests', $this->headerFor($user))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'status', 'jersey_number', 'team' => ['id', 'name', 'logo_url'], 'tournament' => ['id', 'name'], 'league', 'requested_by', 'requested_at']]]);

        $this->postJson("/api/player/roster-requests/{$keep->id}/accept", [], $this->headerFor($user))
            ->assertOk()->assertJsonPath('status', 'active');

        $this->assertSame(RosterStatus::CANCELLED, $other->fresh()?->status);
        $this->getJson('/api/player/roster-requests', $this->headerFor($user))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_player_rejects_and_cannot_answer_someone_elses_request(): void
    {
        [$registration] = $this->registrationWithManager($this->mixedTournament());
        $player = $this->playerWithCode('ABCD2345');
        $row = $this->rosterRow($registration, $player, RosterStatus::PENDING, 9);
        $intruder = $this->playerWithCode('EFGH6789')->user;
        $user = $player->user;
        assert($intruder !== null && $user !== null);

        $this->postJson("/api/player/roster-requests/{$row->id}/accept", [], $this->headerFor($intruder))->assertForbidden();
        $this->postJson("/api/player/roster-requests/{$row->id}/reject", [], $this->headerFor($user))
            ->assertOk()->assertJsonPath('status', 'rejected');
        $this->postJson("/api/player/roster-requests/{$row->id}/accept", [], $this->headerFor($user))
            ->assertStatus(422)->assertJsonPath('error', Message::ROSTER_NOT_PENDING);
    }
}
