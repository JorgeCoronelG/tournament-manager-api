<?php

namespace Tests\Feature\Referee;

use App\Core\Enum\LeagueRefereeStatus;
use App\Core\Enum\Message;
use App\Core\Enum\Role as RoleEnum;
use App\Core\Enum\RosterStatus;
use App\Core\Enum\TournamentStatus;
use App\Models\GameMatch;
use App\Models\LeagueReferee;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Registration;
use App\Models\Scan;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Rosters\InteractsWithRosters;
use Tests\TestCase;

/**
 * Endpoints del árbitro en la app móvil: sus partidos, la plantilla para validar sin internet y el envío por lote
 * de escaneos, eventos y marcador.
 */
class RefereeMatchesTest extends TestCase
{
    use InteractsWithRosters, RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function headerFor(User $user): array
    {
        // El guard de Sanctum guarda al usuario de la petición anterior; se olvida para autenticar con este token.
        $this->app['auth']->forgetGuards();

        return $this->authHeader($user->createToken('t')->plainTextToken) + ['Accept' => 'application/json'];
    }

    private function referee(Tournament $tournament, LeagueRefereeStatus $status = LeagueRefereeStatus::ACTIVE): User
    {
        $user = User::factory()->create(['user_code' => Str::upper(Str::random(8)), 'email_verified_at' => now()]);
        $user->roles()->attach($this->createRole(RoleEnum::REFEREE));
        LeagueReferee::factory()->create(['league_id' => $tournament->league_id, 'user_id' => $user->id, 'status' => $status]);

        return $user;
    }

    /**
     * Torneo en curso con dos equipos y un partido de hoy a las 10:00 pitado por el árbitro.
     *
     * @return array{0: GameMatch, 1: User, 2: Registration, 3: Registration}
     */
    private function matchToday(): array
    {
        $tournament = $this->mixedTournament(['status' => TournamentStatus::IN_PROGRESS]);
        [$home] = $this->registrationWithManager($tournament);
        [$away] = $this->registrationWithManager($tournament);
        $referee = $this->referee($tournament);

        return [$this->match($tournament, $home, $away, $referee, now()->setTime(10, 0)), $referee, $home, $away];
    }

    private function match(Tournament $tournament, Registration $home, Registration $away, ?User $referee, ?\DateTimeInterface $at): GameMatch
    {
        $match = new GameMatch;
        $match->forceFill([
            'tournament_id' => $tournament->id,
            'home_registration_id' => $home->id,
            'away_registration_id' => $away->id,
            'referee_user_id' => $referee?->id,
            'phase' => 'regular_stage',
            'matchday' => 1,
            'match_datetime' => $at,
            'status' => 'scheduled',
        ])->save();

        return $match;
    }

    private function activePlayer(Registration $registration, int $jersey): Player
    {
        $player = $this->player();
        $this->rosterRow($registration, $player, RosterStatus::ACTIVE, $jersey);

        return $player;
    }

    /**
     * @return array<string, mixed>
     */
    private function scan(string $code, array $extra = []): array
    {
        return ['client_uuid' => (string) Str::uuid(), 'scanned_code' => $code, 'scanned_at' => now()->toIso8601String(), ...$extra];
    }

    public function test_the_referee_lists_only_his_dated_matches_of_active_leagues(): void
    {
        [$mine, $referee, $home, $away] = $this->matchToday();
        $tournament = Tournament::query()->findOrFail($mine->tournament_id);
        $other = $this->referee($tournament);
        $this->match($tournament, $home, $away, $other, now());
        $this->match($tournament, $home, $away, $referee, null);
        $this->match($tournament, $home, $away, $referee, now()->addDays(60));

        // Partido en otra liga donde ya no está activo.
        $elsewhere = $this->mixedTournament(['status' => TournamentStatus::IN_PROGRESS]);
        LeagueReferee::factory()->create(['league_id' => $elsewhere->league_id, 'user_id' => $referee->id, 'status' => LeagueRefereeStatus::INACTIVE]);
        [$h2] = $this->registrationWithManager($elsewhere);
        [$a2] = $this->registrationWithManager($elsewhere);
        $this->match($elsewhere, $h2, $a2, $referee, now());

        $this->getJson('/api/referee/matches', $this->headerFor($referee))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.home.registration_id', $home->id)
            ->assertJsonPath('data.0.away.registration_id', $away->id)
            ->assertJsonPath('data.0.tournament.status', 'in_progress')
            ->assertJsonPath('data.0.knockout', null);

        $this->getJson('/api/referee/matches?from='.now()->addDays(59)->toDateString().'&to='.now()->addDays(61)->toDateString(), $this->headerFor($referee))
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_only_referees_use_these_endpoints(): void
    {
        [$match] = $this->matchToday();
        $player = $this->player()->user;
        assert($player !== null);
        $player->roles()->attach($this->createRole(RoleEnum::PLAYER));

        $this->getJson('/api/referee/matches', $this->headerFor($player))->assertForbidden();
        $this->getJson("/api/referee/matches/{$match->id}/rosters", $this->headerFor($player))->assertForbidden();
    }

    public function test_a_match_of_another_referee_is_forbidden(): void
    {
        [$match] = $this->matchToday();
        $stranger = $this->referee(Tournament::query()->findOrFail($match->tournament_id));

        $this->getJson("/api/referee/matches/{$match->id}/rosters", $this->headerFor($stranger))->assertForbidden();
        $this->postJson("/api/referee/matches/{$match->id}/sync", ['scans' => []], $this->headerFor($stranger))->assertForbidden();
    }

    public function test_the_rosters_bring_qr_photo_and_eligibility_of_both_teams(): void
    {
        [$match, $referee, $home, $away] = $this->matchToday();
        $ok = $this->activePlayer($home, 7);
        $banned = $this->activePlayer($home, 8);
        $suspended = $this->activePlayer($away, 9);
        $removed = $this->player();
        $this->rosterRow($away, $removed, RosterStatus::REMOVED, 10);
        $this->rosterRow($away, $this->player(), RosterStatus::PENDING, 11);

        $tournament = Tournament::query()->findOrFail($match->tournament_id);
        DB::table('player_league_bans')->insert([
            'player_id' => $banned->id, 'league_id' => $tournament->league_id, 'reason' => 'Agresión',
            'banned_by_user_id' => $referee->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sanctions')->insert([
            'player_id' => $suspended->id, 'tournament_id' => $tournament->id, 'suspension_matches' => 1,
            'is_completed' => false, 'is_permanent' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->getJson("/api/referee/matches/{$match->id}/rosters", $this->headerFor($referee))
            ->assertOk()
            ->assertJsonPath('match_id', $match->id)
            ->assertJsonCount(2, 'teams')
            ->assertJsonPath('teams.0.registration_id', $home->id)
            ->assertJsonCount(2, 'teams.0.players')
            ->assertJsonCount(2, 'teams.1.players');

        $players = collect($response->json('teams'))->flatMap(fn (array $team) => $team['players'])->keyBy('player_id');
        $this->assertSame('eligible', $players[$ok->id]['eligibility']);
        $this->assertSame($ok->qr_code, $players[$ok->id]['qr_code']);
        $this->assertSame(7, $players[$ok->id]['jersey_number']);
        $this->assertArrayHasKey('photo_url', $players[$ok->id]);
        $this->assertSame('banned', $players[$banned->id]['eligibility']);
        $this->assertSame('suspended', $players[$suspended->id]['eligibility']);
        $this->assertSame('removed', $players[$removed->id]['status']);
    }

    public function test_the_server_decides_each_scan_result_and_ignores_repeated_uuids(): void
    {
        [$match, $referee, $home, $away] = $this->matchToday();
        $valid = $this->activePlayer($home, 7);
        $late = $this->activePlayer($away, 8);
        $removed = $this->player();
        $this->rosterRow($away, $removed, RosterStatus::REMOVED, 10);
        $pending = $this->player();
        $this->rosterRow($away, $pending, RosterStatus::PENDING, 11);
        $outsider = $this->player();

        $scans = [
            $this->scan($valid->qr_code, ['result' => 'banned']),
            $this->scan('NO-EXISTE'),
            $this->scan($removed->qr_code),
            $this->scan($pending->qr_code),
            $this->scan($outsider->qr_code),
            ['client_uuid' => (string) Str::uuid(), 'method' => 'manual', 'player_id' => $late->id, 'scanned_at' => now()->toIso8601String()],
        ];

        $response = $this->postJson("/api/referee/matches/{$match->id}/sync", ['scans' => $scans], $this->headerFor($referee))
            ->assertOk()
            ->assertJsonPath('result.status', 'none');

        $this->assertSame(
            ['valid', 'unknown_qr', 'removed', 'not_registered', 'not_registered', 'valid'],
            array_column($response->json('scans'), 'result')
        );

        $manual = Scan::query()->where('client_uuid', $scans[5]['client_uuid'])->firstOrFail();
        $this->assertSame('manual', $manual->method->value);
        $this->assertSame($late->qr_code, $manual->scanned_code);
        $this->assertSame($away->id, $manual->registration_id);
        $this->assertSame($referee->id, (int) $manual->scanned_by_user_id);

        // Reenviar el mismo lote (se perdió la respuesta) no duplica nada.
        $this->postJson("/api/referee/matches/{$match->id}/sync", ['scans' => $scans], $this->headerFor($referee))->assertOk();
        $this->assertSame(6, Scan::query()->count());
    }

    public function test_events_are_saved_once_and_can_be_voided_later(): void
    {
        [$match, $referee, $home, $away] = $this->matchToday();
        $scorer = $this->activePlayer($home, 7);
        $event = [
            'client_uuid' => (string) Str::uuid(), 'registration_id' => $home->id, 'player_id' => $scorer->id,
            'type' => 'goal', 'minute' => 3, 'period' => 'second_half', 'recorded_at' => now()->toIso8601String(), 'is_voided' => false,
        ];
        $url = "/api/referee/matches/{$match->id}/sync";

        $this->postJson($url, ['events' => [$event]], $this->headerFor($referee))->assertOk()->assertJsonPath('events', 1);
        $this->postJson($url, ['events' => [[...$event, 'is_voided' => true]]], $this->headerFor($referee))->assertOk();

        $saved = MatchEvent::query()->sole();
        $this->assertTrue($saved->is_voided);
        $this->assertSame('second_half', $saved->period?->value);
        $this->assertSame(3, $saved->minute);

        $this->postJson($url, ['events' => [[...$event, 'client_uuid' => (string) Str::uuid(), 'registration_id' => $away->id]]], $this->headerFor($referee))
            ->assertStatus(422)->assertJsonPath('error', Message::REFEREE_EVENT_PLAYER_NOT_IN_TEAM);

        [$foreign] = $this->registrationWithManager(Tournament::query()->findOrFail($match->tournament_id));
        $this->postJson($url, ['events' => [[...$event, 'client_uuid' => (string) Str::uuid(), 'registration_id' => $foreign->id]]], $this->headerFor($referee))
            ->assertStatus(422)->assertJsonPath('error', Message::REFEREE_EVENT_TEAM_NOT_IN_MATCH);

        $this->postJson($url, ['events' => [[...$event, 'client_uuid' => (string) Str::uuid(), 'period' => 'tercer_tiempo']]], $this->headerFor($referee))
            ->assertStatus(422);
    }

    public function test_the_result_follows_the_panel_rules_without_losing_the_rest(): void
    {
        [$match, $referee, $home] = $this->matchToday();
        $player = $this->activePlayer($home, 7);
        $url = "/api/referee/matches/{$match->id}/sync";

        // Fase regular sin penales: el marcador se rechaza pero el escaneo se guarda.
        $this->postJson($url, [
            'scans' => [$this->scan($player->qr_code)],
            'result' => ['home_score' => 1, 'away_score' => 1, 'home_penalties' => 4, 'away_penalties' => 3],
        ], $this->headerFor($referee))
            ->assertOk()
            ->assertJsonPath('scans.0.result', 'valid')
            ->assertJsonPath('result.status', 'rejected')
            ->assertJsonPath('result.message', Message::MATCH_REGULAR_NO_PENALTIES);
        $this->assertSame(1, Scan::query()->count());

        $this->postJson($url, ['result' => ['home_score' => 2, 'away_score' => 1]], $this->headerFor($referee))
            ->assertOk()->assertJsonPath('result.status', 'saved');
        $this->assertSame('played', $match->fresh()?->status->value);
        $this->assertSame(2, (int) $match->fresh()?->home_score);

        // El mismo marcador otra vez (se perdió la respuesta) cuenta como guardado; otro distinto, no lo pisa.
        $this->postJson($url, ['result' => ['home_score' => 2, 'away_score' => 1]], $this->headerFor($referee))
            ->assertOk()->assertJsonPath('result.status', 'saved');
        $this->postJson($url, ['result' => ['home_score' => 0, 'away_score' => 0]], $this->headerFor($referee))
            ->assertOk()->assertJsonPath('result.status', 'already_recorded');
        $this->assertSame(2, (int) $match->fresh()?->home_score);
    }

    public function test_a_finished_tournament_is_read_only(): void
    {
        [$match, $referee] = $this->matchToday();
        Tournament::query()->findOrFail($match->tournament_id)->forceFill(['status' => TournamentStatus::FINISHED])->save();

        $this->postJson("/api/referee/matches/{$match->id}/sync", ['scans' => [$this->scan('X')]], $this->headerFor($referee))
            ->assertStatus(422)->assertJsonPath('error', Message::MATCH_TOURNAMENT_FINISHED);
    }
}
