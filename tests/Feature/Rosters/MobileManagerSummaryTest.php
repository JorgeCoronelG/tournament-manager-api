<?php

namespace Tests\Feature\Rosters;

use App\Core\Enum\MatchPhase;
use App\Core\Enum\MatchStatus;
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
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Mi equipo" del delegado: goles y tarjetas por jugador, suspensiones y vetos, y partidos con marcador.
 */
class MobileManagerSummaryTest extends TestCase
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

    private function rival(Tournament $tournament, string $name): Registration
    {
        $team = Team::factory()->create(['league_id' => $tournament->league_id, 'name' => $name]);

        return Registration::factory()->create(['team_id' => $team->id, 'tournament_id' => $tournament->id]);
    }

    /**
     * @param  array{0: int, 1: int}|null  $score
     */
    private function match(Registration $home, Registration $away, string $datetime, ?array $score = null): GameMatch
    {
        $match = new GameMatch;
        $match->forceFill([
            'tournament_id' => $home->tournament_id,
            'home_registration_id' => $home->id,
            'away_registration_id' => $away->id,
            'phase' => MatchPhase::REGULAR_STAGE,
            'matchday' => 1,
            'match_datetime' => $datetime,
            'status' => $score === null ? MatchStatus::SCHEDULED : MatchStatus::PLAYED,
            'home_score' => $score[0] ?? null,
            'away_score' => $score[1] ?? null,
        ])->save();

        return $match;
    }

    private function event(GameMatch $match, Registration $registration, Player $player, string $type, bool $voided = false): void
    {
        DB::table('match_events')->insert([
            'client_uuid' => (string) Str::uuid(),
            'match_id' => $match->id,
            'registration_id' => $registration->id,
            'player_id' => $player->id,
            'type' => $type,
            'minute' => 10,
            'recorded_by_user_id' => User::factory()->create()->id,
            'recorded_at' => now(),
            'is_voided' => $voided,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function url(Registration $registration): string
    {
        return "/api/manager/registrations/{$registration->id}/summary";
    }

    public function test_the_manager_gets_goals_cards_eligibility_and_matches_of_his_team(): void
    {
        $tournament = $this->mixedTournament(['status' => TournamentStatus::IN_PROGRESS]);
        [$registration, $manager] = $this->registrationWithManager($tournament);
        $rival = $this->rival($tournament, 'Rayos FC');
        $scorer = $this->player();
        $suspended = $this->player();
        $removed = $this->player();
        $pending = $this->player();
        $this->rosterRow($registration, $scorer, RosterStatus::ACTIVE, 9);
        $this->rosterRow($registration, $suspended, RosterStatus::ACTIVE, 4);
        $this->rosterRow($registration, $removed, RosterStatus::REMOVED, 7);
        $this->rosterRow($registration, $pending, RosterStatus::PENDING, 8);

        $played = $this->match($rival, $registration, now()->subDays(7)->toDateTimeString(), [1, 3]);
        $next = $this->match($registration, $rival, now()->addDays(3)->toDateTimeString());
        $this->event($played, $registration, $scorer, 'goal');
        $this->event($played, $registration, $scorer, 'goal');
        $this->event($played, $registration, $scorer, 'goal', voided: true);
        $this->event($played, $registration, $scorer, 'yellow_card');
        $this->event($played, $registration, $suspended, 'red_card');
        // Un evento de un partido que todavía no se juega no cuenta.
        $this->event($next, $registration, $scorer, 'goal');

        DB::table('sanctions')->insert([
            'player_id' => $suspended->id, 'tournament_id' => $tournament->id, 'match_id' => $played->id,
            'suspension_matches' => 2, 'is_completed' => false, 'is_permanent' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->getJson($this->url($registration), $this->headerFor($manager))->assertOk();

        $players = collect($response->json('players'))->keyBy('player_id');
        $this->assertCount(3, $players);
        $this->assertFalse($players->has($pending->id));
        $this->assertSame(2, $players[$scorer->id]['goals']);
        $this->assertSame(1, $players[$scorer->id]['yellow_cards']);
        $this->assertSame('eligible', $players[$scorer->id]['eligibility']);
        $this->assertNull($players[$scorer->id]['suspension_matches_left']);
        $this->assertSame(1, $players[$suspended->id]['red_cards']);
        $this->assertSame('suspended', $players[$suspended->id]['eligibility']);
        $this->assertSame(2, $players[$suspended->id]['suspension_matches_left']);
        $this->assertSame(0, $players[$removed->id]['goals']);

        $response
            ->assertJsonCount(2, 'matches')
            ->assertJsonPath('matches.0.id', $played->id)
            ->assertJsonPath('matches.0.is_home', false)
            ->assertJsonPath('matches.0.opponent', 'Rayos FC')
            ->assertJsonPath('matches.0.goals_for', 3)
            ->assertJsonPath('matches.0.goals_against', 1)
            ->assertJsonPath('matches.0.status', 'played')
            ->assertJsonPath('matches.1.id', $next->id)
            ->assertJsonPath('matches.1.is_home', true)
            ->assertJsonPath('matches.1.goals_for', null);
    }

    public function test_banned_players_show_up_as_banned(): void
    {
        $tournament = $this->mixedTournament(['status' => TournamentStatus::IN_PROGRESS]);
        [$registration, $manager] = $this->registrationWithManager($tournament);
        $player = $this->player();
        $this->rosterRow($registration, $player, RosterStatus::ACTIVE);
        DB::table('player_league_bans')->insert([
            'player_id' => $player->id, 'league_id' => $tournament->league_id, 'reason' => 'Agresión',
            'banned_by_user_id' => $manager->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->getJson($this->url($registration), $this->headerFor($manager))
            ->assertOk()
            ->assertJsonPath('players.0.eligibility', 'banned');
    }

    public function test_only_the_manager_of_the_team_sees_the_summary(): void
    {
        [$registration] = $this->registrationWithManager($this->mixedTournament());
        $stranger = $this->createManagerUser();

        $this->getJson($this->url($registration), $this->headerFor($stranger))->assertForbidden();
    }
}
