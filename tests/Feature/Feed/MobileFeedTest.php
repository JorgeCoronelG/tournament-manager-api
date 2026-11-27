<?php

namespace Tests\Feature\Feed;

use App\Core\Enum\MatchPhase;
use App\Core\Enum\MatchStatus;
use App\Core\Enum\PlayerGender;
use App\Core\Enum\Role as RoleEnum;
use App\Core\Enum\RosterStatus;
use App\Core\Enum\TournamentStatus;
use App\Models\GameMatch;
use App\Models\League;
use App\Models\Player;
use App\Models\Registration;
use App\Models\Roster;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Teams\InteractsWithTeams;
use Tests\TestCase;

/**
 * Inicio y Tabla de la app móvil: partidos de hoy, próximos y resultados de los torneos de las ligas del usuario,
 * detalle de un partido con sus eventos y estadísticas del torneo.
 */
class MobileFeedTest extends TestCase
{
    use InteractsWithTeams, RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function headerFor(User $user): array
    {
        $this->app['auth']->forgetGuards();

        return $this->authHeader($user->createToken('t')->plainTextToken) + ['Accept' => 'application/json'];
    }

    private function runningTournament(League $league, string $name): Tournament
    {
        return Tournament::factory()->status(TournamentStatus::IN_PROGRESS)->create(['league_id' => $league->id, 'name' => $name]);
    }

    private function registration(Tournament $tournament, string $team, ?User $manager = null): Registration
    {
        $team = Team::factory()->create(['league_id' => $tournament->league_id, 'name' => $team, 'manager_user_id' => $manager?->id]);

        return Registration::factory()->create(['team_id' => $team->id, 'tournament_id' => $tournament->id]);
    }

    private function playerIn(Registration $registration, int $jersey, string $first): Player
    {
        $player = Player::factory()->gender(PlayerGender::MALE)->create(['birth_date' => '1995-05-05']);
        $player->user?->forceFill(['first_name' => $first, 'last_name' => 'Prueba'])->save();
        Roster::factory()->forRegistration($registration)->status(RosterStatus::ACTIVE)->create(['player_id' => $player->id, 'jersey_number' => $jersey]);

        return $player;
    }

    private function match(Registration $home, Registration $away, string $datetime, ?array $score = null, MatchStatus $status = MatchStatus::SCHEDULED): GameMatch
    {
        $match = new GameMatch;
        $match->forceFill([
            'tournament_id' => $home->tournament_id,
            'group_id' => $home->group_id,
            'home_registration_id' => $home->id,
            'away_registration_id' => $away->id,
            'phase' => MatchPhase::REGULAR_STAGE,
            'matchday' => 1,
            'match_datetime' => $datetime,
            'status' => $score === null ? $status : MatchStatus::PLAYED,
            'home_score' => $score[0] ?? null,
            'away_score' => $score[1] ?? null,
        ])->save();

        return $match;
    }

    private function event(GameMatch $match, Registration $registration, Player $player, string $type, int $minute, bool $voided = false): void
    {
        DB::table('match_events')->insert([
            'client_uuid' => (string) Str::uuid(),
            'match_id' => $match->id,
            'registration_id' => $registration->id,
            'player_id' => $player->id,
            'type' => $type,
            'minute' => $minute,
            'recorded_by_user_id' => User::factory()->create()->id,
            'recorded_at' => now(),
            'is_voided' => $voided,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Liga del jugador con un torneo en curso (Halcones, Rayos y Toros) y otra liga ajena con su propio torneo.
     *
     * @return array{player: Player, user: User, mine: Tournament, other: Tournament, halcones: Registration, rayos: Registration, toros: Registration, played: GameMatch, foreign: GameMatch}
     */
    private function scenario(): array
    {
        [$league] = $this->leagueWithAdminHeader();
        [$otherLeague] = $this->leagueWithAdminHeader();
        $mine = $this->runningTournament($league, 'Apertura');
        $other = $this->runningTournament($otherLeague, 'Ajeno');

        $halcones = $this->registration($mine, 'Halcones');
        $rayos = $this->registration($mine, 'Rayos');
        $toros = $this->registration($mine, 'Toros');
        $player = $this->playerIn($halcones, 9, 'Hugo');
        $rival = $this->playerIn($rayos, 4, 'Raúl');
        $user = $player->user;
        assert($user !== null);
        $user->roles()->attach($this->createRole(RoleEnum::PLAYER));

        // FIXED_NOW = 2026-10-26 10:00
        $played = $this->match($halcones, $rayos, '2026-10-20 18:00:00', [2, 1]);
        $this->match($rayos, $toros, '2026-10-19 18:00:00', [0, 0]);
        $this->match($halcones, $toros, '2026-10-26 17:00:00');
        $this->match($rayos, $toros, '2026-10-29 17:00:00');
        $this->match($toros, $halcones, '2026-11-15 17:00:00');
        $this->event($played, $halcones, $player, 'goal', 30);
        $this->event($played, $halcones, $player, 'goal', 70);
        $this->event($played, $rayos, $rival, 'goal', 80);
        $this->event($played, $rayos, $rival, 'yellow_card', 50);
        $this->event($played, $rayos, $rival, 'goal', 85, voided: true);

        $foreign = $this->match($this->registration($other, 'A'), $this->registration($other, 'B'), '2026-10-26 12:00:00');

        return compact('player', 'user', 'mine', 'other', 'halcones', 'rayos', 'toros', 'played', 'foreign');
    }

    public function test_home_shows_today_upcoming_and_results_of_the_users_leagues_only(): void
    {
        $s = $this->scenario();

        $response = $this->getJson('/api/feed', $this->headerFor($s['user']))->assertOk();

        $this->assertSame([$s['mine']->id], array_column($response->json('tournaments'), 'id'));
        $this->assertSame([$s['halcones']->id], $response->json('my_registration_ids'));
        $this->assertSame(['Halcones'], array_map(fn (array $match) => $match['home']['team']['name'], $response->json('today')));
        // Próximos: solo los 7 días siguientes.
        $this->assertCount(1, $response->json('upcoming'));
        $this->assertSame('2026-10-29', substr((string) $response->json('upcoming.0.match_datetime'), 0, 10));
        // Resultados: del más reciente al más viejo.
        $this->assertSame([$s['played']->id], array_slice(array_column($response->json('results.data'), 'id'), 0, 1));
        $this->assertCount(2, $response->json('results.data'));
        $this->assertNull($response->json('results.next_cursor'));
        $this->assertSame(2, $response->json('results.data.0.home_score'));
    }

    public function test_filters_by_my_team_and_tournament(): void
    {
        $s = $this->scenario();
        $header = $this->headerFor($s['user']);

        $mine = $this->getJson('/api/feed?mine=1', $header)->assertOk();
        $this->assertSame([$s['played']->id], array_column($mine->json('results.data'), 'id'));
        $this->assertCount(0, $mine->json('upcoming'));

        // Un torneo ajeno no se puede consultar: la lista sale vacía.
        $this->getJson("/api/feed?tournament_id={$s['other']->id}", $header)->assertOk()->assertJsonCount(0, 'today');
        $this->getJson('/api/feed/results?mine=1&cursor=0', $header)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/feed?mine=x', $header)->assertStatus(400);
    }

    public function test_a_user_without_leagues_sees_every_active_league(): void
    {
        $s = $this->scenario();
        $newcomer = Player::factory()->create()->user;
        assert($newcomer !== null);

        $ids = array_column($this->getJson('/api/feed', $this->headerFor($newcomer))->assertOk()->json('tournaments'), 'id');

        $this->assertContains($s['mine']->id, $ids);
        $this->assertContains($s['other']->id, $ids);
    }

    public function test_results_are_paginated_with_a_cursor(): void
    {
        $s = $this->scenario();
        for ($day = 1; $day <= 10; $day++) {
            $this->match($s['rayos'], $s['toros'], sprintf('2026-09-%02d 10:00:00', $day), [1, 0]);
        }
        $header = $this->headerFor($s['user']);

        $first = $this->getJson('/api/feed/results', $header)->assertOk();
        $this->assertCount(10, $first->json('data'));
        $this->assertSame('10', $first->json('next_cursor'));

        $second = $this->getJson('/api/feed/results?cursor=10', $header)->assertOk();
        $this->assertCount(2, $second->json('data'));
        $this->assertNull($second->json('next_cursor'));
    }

    public function test_match_detail_has_events_with_names_and_jerseys(): void
    {
        $s = $this->scenario();
        $header = $this->headerFor($s['user']);

        $response = $this->getJson("/api/feed/matches/{$s['played']->id}", $header)->assertOk()
            ->assertJsonPath('home.team.name', 'Halcones')
            ->assertJsonPath('home_score', 2);

        $events = $response->json('events');
        $this->assertSame([30, 50, 70, 80], array_column($events, 'minute'));
        $this->assertSame('Hugo Prueba', $events[0]['player']['name']);
        $this->assertSame(9, $events[0]['player']['jersey_number']);
        $this->assertSame([], $response->json('lineups'));

        $this->getJson("/api/feed/matches/{$s['foreign']->id}", $header)->assertNotFound();
    }

    public function test_stats_have_standings_with_form_scorers_and_cards(): void
    {
        $s = $this->scenario();
        $header = $this->headerFor($s['user']);

        $response = $this->getJson("/api/feed/tournaments/{$s['mine']->id}/stats", $header)->assertOk()
            ->assertJsonPath('tournament.id', $s['mine']->id)
            ->assertJsonPath('my_registration_ids', [$s['halcones']->id])
            ->assertJsonCount(1, 'groups');

        $rows = $response->json('groups.0.rows');
        $this->assertSame('Halcones', $rows[0]['team']['name']);
        $this->assertSame(3, $rows[0]['points']);
        $this->assertSame(['G'], $rows[0]['form']);
        $rayos = collect($rows)->firstWhere('registration_id', $s['rayos']->id);
        $this->assertSame(['E', 'P'], $rayos['form']);

        $this->assertSame([['Hugo Prueba', 2], ['Raúl Prueba', 1]], array_map(fn ($r) => [$r['name'], $r['goals']], $response->json('scorers')));
        $this->assertSame('Raúl Prueba', $response->json('cards.0.name'));
        $this->assertSame(1, $response->json('cards.0.yellow_cards'));

        $this->getJson("/api/feed/tournaments/{$s['other']->id}/stats", $header)->assertNotFound();
    }

    public function test_requires_a_session(): void
    {
        $this->getJson('/api/feed')->assertUnauthorized();
    }
}
