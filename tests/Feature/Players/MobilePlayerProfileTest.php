<?php

namespace Tests\Feature\Players;

use App\Core\Enum\MatchPhase;
use App\Core\Enum\MatchStatus;
use App\Core\Enum\Message;
use App\Core\Enum\PlayerGender;
use App\Core\Enum\Role as RoleEnum;
use App\Core\Enum\RosterStatus;
use App\Core\Enum\TournamentStatus;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\Registration;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Rosters\InteractsWithRosters;
use Tests\TestCase;

/**
 * "Mi QR" de la app móvil: el jugador recibe su qr_code real, sus equipos con elegibilidad y sus próximos partidos.
 */
class MobilePlayerProfileTest extends TestCase
{
    use InteractsWithRosters, RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function headerFor(User $user): array
    {
        $this->app['auth']->forgetGuards();

        return $this->authHeader($user->createToken('t')->plainTextToken) + ['Accept' => 'application/json'];
    }

    private function playerUser(Player $player): User
    {
        $user = $player->user;
        assert($user !== null);
        $user->roles()->attach($this->createRole(RoleEnum::PLAYER));

        return $user;
    }

    private function rival(Tournament $tournament, string $name): Registration
    {
        $team = Team::factory()->create(['league_id' => $tournament->league_id, 'name' => $name]);

        return Registration::factory()->create(['team_id' => $team->id, 'tournament_id' => $tournament->id]);
    }

    private function match(Registration $home, Registration $away, string $datetime, MatchStatus $status = MatchStatus::SCHEDULED): GameMatch
    {
        $match = new GameMatch;
        $match->forceFill([
            'tournament_id' => $home->tournament_id,
            'home_registration_id' => $home->id,
            'away_registration_id' => $away->id,
            'phase' => MatchPhase::REGULAR_STAGE,
            'matchday' => 3,
            'match_datetime' => $datetime,
            'status' => $status,
        ])->save();

        return $match;
    }

    public function test_the_player_gets_his_real_qr_teams_and_upcoming_matches(): void
    {
        $tournament = $this->mixedTournament(['status' => TournamentStatus::IN_PROGRESS]);
        [$registration] = $this->registrationWithManager($tournament);
        $player = $this->player();
        $user = $this->playerUser($player);
        $this->rosterRow($registration, $player, RosterStatus::ACTIVE, 9);
        $rival = $this->rival($tournament, 'Rayos FC');

        $next = $this->match($rival, $registration, now()->addDays(2)->toDateTimeString());
        $this->match($registration, $rival, now()->addDays(9)->toDateTimeString());
        $this->match($registration, $rival, now()->subDays(3)->toDateTimeString());
        $this->match($registration, $rival, now()->addDay()->toDateTimeString(), MatchStatus::CANCELLED);

        $response = $this->getJson('/api/player/me', $this->headerFor($user))
            ->assertOk()
            ->assertJsonPath('player_id', $player->id)
            ->assertJsonPath('qr_code', $player->qr_code)
            ->assertJsonPath('teams.0.registration_id', $registration->id)
            ->assertJsonPath('teams.0.jersey_number', 9)
            ->assertJsonPath('teams.0.status', 'active')
            ->assertJsonPath('teams.0.eligibility', 'eligible')
            ->assertJsonPath('upcoming_matches.0.id', $next->id)
            ->assertJsonPath('upcoming_matches.0.home_team', 'Rayos FC')
            ->assertJsonPath('upcoming_matches.0.matchday', 3);

        $this->assertCount(2, $response->json('upcoming_matches'));
    }

    public function test_pending_rosters_and_finished_tournaments_are_left_out(): void
    {
        $finished = $this->mixedTournament(['status' => TournamentStatus::FINISHED]);
        [$old] = $this->registrationWithManager($finished);
        [$pending] = $this->registrationWithManager($this->mixedTournament(['status' => TournamentStatus::PLANNING]));
        $player = $this->player();
        $user = $this->playerUser($player);
        $this->rosterRow($old, $player, RosterStatus::ACTIVE);
        $this->rosterRow($pending, $player, RosterStatus::PENDING);

        $this->getJson('/api/player/me', $this->headerFor($user))
            ->assertOk()
            ->assertJsonPath('qr_code', $player->qr_code)
            ->assertJsonCount(0, 'teams')
            ->assertJsonCount(0, 'upcoming_matches');
    }

    public function test_bans_and_suspensions_show_up_like_the_referee_sees_them(): void
    {
        $tournament = $this->mixedTournament(['status' => TournamentStatus::IN_PROGRESS]);
        [$registration] = $this->registrationWithManager($tournament);
        $other = $this->mixedTournament(['status' => TournamentStatus::IN_PROGRESS]);
        [$otherRegistration, $manager] = $this->registrationWithManager($other);
        $player = $this->player();
        $user = $this->playerUser($player);
        $this->rosterRow($registration, $player, RosterStatus::ACTIVE, 4);
        $this->rosterRow($otherRegistration, $player, RosterStatus::REMOVED, 5);

        DB::table('sanctions')->insert([
            'player_id' => $player->id, 'tournament_id' => $tournament->id, 'suspension_matches' => 1,
            'is_completed' => false, 'is_permanent' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('player_league_bans')->insert([
            'player_id' => $player->id, 'league_id' => $other->league_id, 'reason' => 'Agresión',
            'banned_by_user_id' => $manager->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $teams = collect($this->getJson('/api/player/me', $this->headerFor($user))->assertOk()->json('teams'))
            ->keyBy('registration_id');

        $this->assertSame('suspended', $teams[$registration->id]['eligibility']);
        $this->assertSame('active', $teams[$registration->id]['status']);
        $this->assertSame('banned', $teams[$otherRegistration->id]['eligibility']);
        $this->assertSame('removed', $teams[$otherRegistration->id]['status']);
    }

    public function test_only_players_with_a_player_record_get_a_profile(): void
    {
        $manager = $this->createManagerUser();
        $this->getJson('/api/player/me', $this->headerFor($manager))->assertForbidden();

        $manager->roles()->attach($this->createRole(RoleEnum::PLAYER));
        $this->getJson('/api/player/me', $this->headerFor($manager))
            ->assertNotFound()->assertJsonPath('error', Message::PLAYER_NOT_FOUND);

        $player = $this->player(PlayerGender::FEMALE);
        $this->getJson('/api/player/me', $this->headerFor($this->playerUser($player)))
            ->assertOk()->assertJsonPath('player_id', $player->id);
    }
}
