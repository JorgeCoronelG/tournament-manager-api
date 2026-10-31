<?php

namespace Tests\Feature\Schedule;

use App\Core\Enum\FieldStatus;
use App\Core\Enum\MatchStatus;
use App\Core\Enum\Message;
use App\Core\Enum\Role as RoleEnum;
use App\Core\Enum\RosterStatus;
use App\Core\Enum\TournamentStatus;
use App\Models\Field;
use App\Models\GameMatch;
use App\Models\League;
use App\Models\LeagueReferee;
use App\Models\Player;
use App\Models\Registration;
use App\Models\Roster;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Groups\InteractsWithGroups;
use Tests\TestCase;

class MatchScheduleEditTest extends TestCase
{
    use InteractsWithGroups, RefreshDatabase;

    private League $league;

    private Tournament $tournament;

    /** @var array<string, string> */
    private array $header;

    /** @var list<GameMatch> */
    private array $matches;

    private Field $fieldA;

    private Field $fieldB;

    private User $referee;

    private User $otherReferee;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->league, $this->tournament, $this->header] = $this->tournamentWithAdminHeader();
        $this->teamsIn($this->tournament, $this->tournament->groups()->firstOrFail(), 4);
        $this->tournament->forceFill(['status' => TournamentStatus::IN_PROGRESS])->save();
        $this->postJson($this->api("tournaments/{$this->tournament->id}/matches/generate"), ['shuffle' => false], $this->header)->assertOk();
        $this->matches = GameMatch::query()->orderBy('id')->get()->all();

        $this->fieldA = Field::factory()->create(['league_id' => $this->league->id]);
        $this->fieldB = Field::factory()->create(['league_id' => $this->league->id]);
        $this->referee = $this->activeReferee();
        $this->otherReferee = $this->activeReferee();
    }

    private function api(string $path): string
    {
        return "/api/leagues/{$this->league->id}/{$path}";
    }

    private function activeReferee(?League $league = null, bool $active = true): User
    {
        $user = User::factory()->create();
        $user->roles()->attach($this->createRole(RoleEnum::REFEREE));
        $link = LeagueReferee::factory();
        $link = $active ? $link : $link->inactive();
        $link->create(['league_id' => ($league ?? $this->league)->id, 'user_id' => $user->id]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function schedule(GameMatch $match, array $body): TestResponse
    {
        return $this->patchJson($this->api("matches/{$match->id}/schedule"), $body, $this->header);
    }

    private function place(GameMatch $match, string $at, ?Field $field = null, ?User $referee = null): void
    {
        $match->forceFill(['match_datetime' => $at, 'field_id' => $field?->id, 'referee_user_id' => $referee?->id, 'schedule_locked' => true])->save();
    }

    /**
     * Otro partido del mismo torneo que comparte algún equipo con $match.
     */
    private function sharingTeam(GameMatch $match): GameMatch
    {
        foreach ($this->matches as $other) {
            if ($other->id !== $match->id && array_intersect([$match->home_registration_id, $match->away_registration_id], [$other->home_registration_id, $other->away_registration_id]) !== []) {
                return $other;
            }
        }

        $this->fail('Sin partido que comparta equipo.');
    }

    /**
     * Otro partido del mismo torneo que NO comparte equipos con $match.
     */
    private function disjoint(GameMatch $match): GameMatch
    {
        foreach ($this->matches as $other) {
            if ($other->id !== $match->id && array_intersect([$match->home_registration_id, $match->away_registration_id], [$other->home_registration_id, $other->away_registration_id]) === []) {
                return $other;
            }
        }

        $this->fail('Sin partido disjunto.');
    }

    public function test_rescheduling_locks_the_match_and_tracks_the_original_time(): void
    {
        $match = $this->matches[0];

        $this->schedule($match, ['match_datetime' => '2026-11-07 08:00:00', 'field_id' => $this->fieldA->id])
            ->assertOk()
            ->assertJsonPath('schedule_locked', true)
            ->assertJsonPath('original_match_datetime', null)
            ->assertJsonPath('field.id', $this->fieldA->id);

        $this->schedule($match, ['match_datetime' => '2026-11-07 09:00:00'])->assertOk()
            ->assertJsonPath('original_match_datetime', fn (?string $at) => str_starts_with((string) $at, '2026-11-07T08:00:00'));

        $this->schedule($match, ['field_id' => $this->fieldB->id])->assertOk()
            ->assertJsonPath('original_match_datetime', fn (?string $at) => str_starts_with((string) $at, '2026-11-07T08:00:00'));

        $this->schedule($match, ['match_datetime' => '2026-11-07 08:00:00'])->assertOk()->assertJsonPath('original_match_datetime', null);

        $this->schedule($match, ['match_datetime' => null])->assertOk()->assertJsonPath('field', null)->assertJsonPath('match_datetime', null);
        $this->schedule($match, ['schedule_locked' => false])->assertOk()->assertJsonPath('schedule_locked', false);
    }

    public function test_field_without_a_date_is_rejected(): void
    {
        $this->schedule($this->matches[0], ['field_id' => $this->fieldA->id])->assertUnprocessable()->assertJsonPath('error', Message::MATCH_SCHEDULE_FIELD_NEEDS_DATETIME);
    }

    public function test_field_and_referee_conflicts_use_overlap_but_consecutive_is_allowed(): void
    {
        [$first] = $this->matches;
        $second = $this->disjoint($first);
        $this->place($first, '2026-11-07 08:00:00', $this->fieldA, $this->referee);

        $this->schedule($second, ['match_datetime' => '2026-11-07 08:30:00', 'field_id' => $this->fieldA->id])
            ->assertUnprocessable()->assertJsonPath('error.code', 'field_conflict');

        $response = $this->schedule($second, ['match_datetime' => '2026-11-07 08:30:00', 'field_id' => $this->fieldB->id, 'referee_user_id' => $this->referee->id])
            ->assertUnprocessable()->assertJsonPath('error.code', 'referee_conflict');
        $this->assertStringContainsString('El árbitro ya está asignado a', (string) $response->json('error.message'));
        $this->assertMatchesRegularExpression('/\(Sáb 07\/11, 8:00 a\.m\.\)/u', (string) $response->json('error.message'));

        $this->schedule($second, ['match_datetime' => '2026-11-07 09:00:00', 'field_id' => $this->fieldA->id, 'referee_user_id' => $this->referee->id])->assertOk();
    }

    public function test_team_conflict_and_same_day_warning(): void
    {
        [$first] = $this->matches;
        $second = $this->sharingTeam($first);
        $this->place($first, '2026-11-07 08:00:00', $this->fieldA);

        $this->schedule($second, ['match_datetime' => '2026-11-07 08:30:00', 'field_id' => $this->fieldB->id])
            ->assertUnprocessable()->assertJsonPath('error.code', 'team_conflict');

        $this->schedule($second, ['match_datetime' => '2026-11-07 12:00:00', 'field_id' => $this->fieldB->id])
            ->assertOk()->assertJsonPath('warnings.0.code', 'same_team_same_day');
    }

    public function test_same_day_warning_is_a_single_message_with_every_team(): void
    {
        $target = $this->matches[0];
        $withHome = collect($this->matches)->first(fn (GameMatch $m) => $m->id !== $target->id && in_array($target->home_registration_id, [$m->home_registration_id, $m->away_registration_id], true));
        $withAway = collect($this->matches)->first(fn (GameMatch $m) => $m->id !== $target->id && $m->id !== $withHome->id && in_array($target->away_registration_id, [$m->home_registration_id, $m->away_registration_id], true));
        $this->place($withHome, '2026-11-07 08:00:00', $this->fieldA);
        $this->place($withAway, '2026-11-07 10:00:00', $this->fieldB);

        $response = $this->schedule($target, ['match_datetime' => '2026-11-07 13:00:00', 'field_id' => $this->fieldA->id])->assertOk();

        $sameDay = array_values(array_filter((array) $response->json('warnings'), fn (array $w) => $w['code'] === 'same_team_same_day'));
        $this->assertCount(1, $sameDay);
        $this->assertMatchesRegularExpression('/^Los equipos .+ y .+ ya juegan ese día\.$/u', $sameDay[0]['message']);
    }

    public function test_only_unlocked_stale_matches_make_the_schedule_outdated(): void
    {
        $this->putJson($this->api("tournaments/{$this->tournament->id}/schedule-config"), [
            'schedule_start_date' => '2026-11-02',
            'windows' => [['weekday' => 6, 'field_id' => $this->fieldA->id, 'start_time' => '08:00', 'end_time' => '10:00']],
            'blocks' => [],
        ], $this->header)->assertOk();
        $capacity = $this->api("tournaments/{$this->tournament->id}/schedule-config/capacity");

        $this->place($this->matches[0], '2026-11-08 09:00:00', $this->fieldA);
        $this->getJson($capacity, $this->header)->assertOk()->assertJsonPath('schedule_outdated', false)->assertJsonPath('locked_outside_schedule', 1);

        $this->matches[1]->forceFill(['match_datetime' => '2026-11-08 09:00:00', 'field_id' => $this->fieldB->id])->save();
        $this->getJson($capacity, $this->header)->assertOk()->assertJsonPath('schedule_outdated', true)->assertJsonPath('locked_outside_schedule', 1);
    }

    public function test_conflicts_include_matches_of_other_tournaments_of_the_league(): void
    {
        [$first] = $this->matches;
        $other = Tournament::factory()->status(TournamentStatus::IN_PROGRESS)->create(['league_id' => $this->league->id]);
        $group = $other->groups()->firstOrFail();
        $shared = Registration::factory()->create(['tournament_id' => $other->id, 'group_id' => $group->id, 'team_id' => Registration::query()->findOrFail($first->home_registration_id)->team_id]);
        $rival = Registration::factory()->create(['tournament_id' => $other->id, 'group_id' => $group->id]);
        $foreign = new GameMatch;
        $foreign->forceFill([
            'tournament_id' => $other->id, 'group_id' => $group->id, 'home_registration_id' => $shared->id, 'away_registration_id' => $rival->id,
            'phase' => 'regular_stage', 'matchday' => 1, 'round_number' => 1, 'status' => MatchStatus::SCHEDULED,
            'match_datetime' => '2026-11-07 08:00:00', 'field_id' => $this->fieldA->id,
        ])->save();

        $this->schedule($first, ['match_datetime' => '2026-11-07 08:30:00', 'field_id' => $this->fieldA->id])
            ->assertUnprocessable()->assertJsonPath('error.code', 'field_conflict');
        $this->schedule($first, ['match_datetime' => '2026-11-07 08:30:00', 'field_id' => $this->fieldB->id])
            ->assertUnprocessable()->assertJsonPath('error.code', 'team_conflict');

        $foreign->forceFill(['status' => MatchStatus::CANCELLED])->save();
        $this->schedule($first, ['match_datetime' => '2026-11-07 08:30:00', 'field_id' => $this->fieldA->id])->assertOk();
    }

    public function test_past_date_and_outside_windows_only_warn(): void
    {
        $this->putJson($this->api("tournaments/{$this->tournament->id}/schedule-config"), [
            'schedule_start_date' => '2026-11-02',
            'windows' => [['weekday' => 6, 'field_id' => $this->fieldA->id, 'start_time' => '08:00', 'end_time' => '12:00']],
            'blocks' => [],
        ], $this->header)->assertOk();

        $codes = fn ($response) => array_column((array) $response->json('warnings'), 'code');

        $inside = $this->schedule($this->matches[0], ['match_datetime' => '2026-11-07 09:00:00', 'field_id' => $this->fieldA->id])->assertOk();
        $this->assertSame([], $codes($inside));

        $outside = $this->schedule($this->matches[0], ['match_datetime' => '2026-11-08 09:00:00', 'field_id' => $this->fieldA->id])->assertOk();
        $this->assertSame(['outside_schedule'], $codes($outside));

        $past = $this->schedule($this->matches[0], ['match_datetime' => '2020-01-04 09:00:00', 'field_id' => $this->fieldA->id])->assertOk();
        $this->assertContains('past_date', $codes($past));
    }

    public function test_invalid_field_and_referee_are_rejected(): void
    {
        $match = $this->matches[0];
        $at = '2026-11-07 08:00:00';

        $this->schedule($match, ['match_datetime' => $at, 'field_id' => Field::factory()->create()->id])->assertUnprocessable()->assertJsonPath('error', Message::MATCH_SCHEDULE_FIELD_UNAVAILABLE);
        $this->schedule($match, ['match_datetime' => $at, 'field_id' => Field::factory()->status(FieldStatus::INACTIVE)->create(['league_id' => $this->league->id])->id])
            ->assertUnprocessable()->assertJsonPath('error', Message::MATCH_SCHEDULE_FIELD_UNAVAILABLE);

        foreach ([$this->activeReferee(active: false), $this->activeReferee(League::factory()->create())] as $invalid) {
            $this->schedule($match, ['match_datetime' => $at, 'referee_user_id' => $invalid->id])->assertUnprocessable()->assertJsonPath('error', Message::MATCH_SCHEDULE_REFEREE_INACTIVE);
        }

        $player = Player::factory()->create(['user_id' => $this->referee->id]);
        $row = new Roster;
        $row->forceFill([
            'registration_id' => $match->home_registration_id, 'team_id' => Registration::query()->findOrFail($match->home_registration_id)->team_id, 'tournament_id' => $this->tournament->id, 'player_id' => $player->id, 'jersey_number' => 9,
            'status' => RosterStatus::ACTIVE, 'requested_by_user_id' => $this->referee->id, 'requested_at' => now(),
        ])->save();

        $this->schedule($match, ['match_datetime' => $at, 'referee_user_id' => $this->referee->id])->assertUnprocessable()->assertJsonPath('error', Message::MATCH_SCHEDULE_REFEREE_PLAYS);
    }

    public function test_played_matches_and_tournaments_not_in_progress_cannot_be_edited(): void
    {
        $this->matches[0]->forceFill(['status' => MatchStatus::PLAYED, 'home_score' => 1, 'away_score' => 0])->save();
        $this->schedule($this->matches[0], ['match_datetime' => '2026-11-07 08:00:00'])->assertUnprocessable()->assertJsonPath('error', Message::MATCH_SCHEDULE_PLAYED);

        $this->tournament->forceFill(['status' => TournamentStatus::FINISHED])->save();
        $this->schedule($this->matches[1], ['match_datetime' => '2026-11-07 08:00:00'])->assertUnprocessable()->assertJsonPath('error', Message::MATCH_SCHEDULE_ONLY_IN_PROGRESS);
    }

    public function test_swap_exchanges_slot_and_referee_and_locks_both(): void
    {
        [$first] = $this->matches;
        $second = $this->disjoint($first);
        $this->place($first, '2026-11-07 08:00:00', $this->fieldA, $this->referee);
        $this->place($second, '2026-11-08 10:00:00', $this->fieldB, $this->otherReferee);

        $this->postJson($this->api("matches/{$first->id}/swap-schedule"), ['with_match_id' => $second->id], $this->header)
            ->assertOk()
            ->assertJsonPath('match.field.id', $this->fieldB->id)
            ->assertJsonPath('match.referee.id', $this->otherReferee->id)
            ->assertJsonPath('with_match.field.id', $this->fieldA->id)
            ->assertJsonPath('with_match.referee.id', $this->referee->id)
            ->assertJsonPath('match.schedule_locked', true);

        $this->assertSame('2026-11-08 10:00', $first->refresh()->match_datetime?->format('Y-m-d H:i'));
        $this->assertSame('2026-11-07 08:00', $first->original_match_datetime?->format('Y-m-d H:i'));
    }

    public function test_swap_with_an_unscheduled_match_moves_the_slot(): void
    {
        [$first] = $this->matches;
        $second = $this->disjoint($first);
        $this->place($first, '2026-11-07 08:00:00', $this->fieldA, $this->referee);

        $this->postJson($this->api("matches/{$first->id}/swap-schedule"), ['with_match_id' => $second->id], $this->header)->assertOk();

        $this->assertNull($first->refresh()->match_datetime);
        $this->assertNull($first->field_id);
        $this->assertNull($first->referee_user_id);
        $this->assertSame($this->fieldA->id, $second->refresh()->field_id);
        $this->assertSame($this->referee->id, $second->referee_user_id);
    }

    public function test_swap_validations(): void
    {
        [$first] = $this->matches;
        $second = $this->disjoint($first);
        $url = $this->api("matches/{$first->id}/swap-schedule");

        $this->postJson($url, ['with_match_id' => $first->id], $this->header)->assertUnprocessable()->assertJsonPath('error', Message::MATCH_SWAP_SAME_MATCH);

        $other = Tournament::factory()->status(TournamentStatus::IN_PROGRESS)->create(['league_id' => $this->league->id]);
        $group = $other->groups()->firstOrFail();
        $foreign = new GameMatch;
        $foreign->forceFill([
            'tournament_id' => $other->id, 'group_id' => $group->id, 'home_registration_id' => Registration::factory()->create(['tournament_id' => $other->id, 'group_id' => $group->id])->id,
            'away_registration_id' => Registration::factory()->create(['tournament_id' => $other->id, 'group_id' => $group->id])->id,
            'phase' => 'regular_stage', 'matchday' => 1, 'round_number' => 1, 'status' => MatchStatus::SCHEDULED,
        ])->save();
        $this->postJson($url, ['with_match_id' => $foreign->id], $this->header)->assertUnprocessable()->assertJsonPath('error', Message::MATCH_SWAP_DIFFERENT_TOURNAMENT);

        $second->forceFill(['status' => MatchStatus::PLAYED, 'home_score' => 0, 'away_score' => 0])->save();
        $this->postJson($url, ['with_match_id' => $second->id], $this->header)->assertUnprocessable()->assertJsonPath('error', Message::MATCH_SCHEDULE_PLAYED);
    }

    public function test_swap_rejects_conflicts_of_the_result(): void
    {
        [$first] = $this->matches;
        $second = $this->disjoint($first);
        $this->place($first, '2026-11-07 08:00:00', $this->fieldA);
        $this->place($second, '2026-11-08 10:00:00', $this->fieldB);

        // Un tercer partido ocupa la cancha B a las 10:30: el primero, al pasar al espacio del segundo, choca con él.
        $third = collect($this->matches)->first(fn (GameMatch $match) => ! in_array($match->id, [$first->id, $second->id], true));
        $this->place($third, '2026-11-08 10:30:00', $this->fieldB);

        $this->postJson($this->api("matches/{$first->id}/swap-schedule"), ['with_match_id' => $second->id], $this->header)
            ->assertUnprocessable()->assertJsonPath('error.code', 'field_conflict');
    }

    public function test_available_slots_and_referees(): void
    {
        [$first] = $this->matches;
        $second = $this->disjoint($first);
        $this->putJson($this->api("tournaments/{$this->tournament->id}/schedule-config"), [
            'schedule_start_date' => '2026-11-02',
            'windows' => [['weekday' => 6, 'field_id' => $this->fieldA->id, 'start_time' => '08:00', 'end_time' => '11:00']],
            'blocks' => [],
        ], $this->header)->assertOk();
        $this->place($second, '2026-11-07 09:00:00', $this->fieldA, $this->referee);

        $slots = $this->getJson($this->api("matches/{$first->id}/available-slots?date=2026-11-07"), $this->header)->assertOk()->assertJsonPath('date', '2026-11-07');
        $this->assertSame(['08:00', '10:00'], array_column((array) $slots->json('slots'), 'start_time'));

        $this->getJson($this->api("matches/{$first->id}/available-slots"), $this->header)->assertOk()->assertJsonPath('date', '2026-11-07');

        $referees = $this->getJson($this->api("matches/{$first->id}/available-referees?match_datetime=".urlencode('2026-11-07 09:30:00')), $this->header)->assertOk();
        $byId = collect($referees->json('referees'))->keyBy('id');
        $this->assertFalse($byId[$this->referee->id]['available']);
        $this->assertStringContainsString('Ocupado en', (string) $byId[$this->referee->id]['reason']);
        $this->assertTrue($byId[$this->otherReferee->id]['available']);
    }

    public function test_generator_keeps_locked_matches_reassigns_the_rest_and_avoids_other_tournaments(): void
    {
        $this->putJson($this->api("tournaments/{$this->tournament->id}/schedule-config"), [
            'schedule_start_date' => '2026-11-02',
            'windows' => [
                ['weekday' => 6, 'field_id' => $this->fieldA->id, 'start_time' => '08:00', 'end_time' => '12:00'],
            ],
            'blocks' => [],
        ], $this->header)->assertOk();

        [$locked, $free] = $this->matches;
        $this->place($locked, '2026-11-07 08:00:00', $this->fieldA, $this->referee);
        $free->forceFill(['referee_user_id' => $this->otherReferee->id, 'original_match_datetime' => '2026-10-01 08:00:00'])->save();

        $other = Tournament::factory()->status(TournamentStatus::IN_PROGRESS)->create(['league_id' => $this->league->id]);
        $group = $other->groups()->firstOrFail();
        $busy = new GameMatch;
        $busy->forceFill([
            'tournament_id' => $other->id, 'group_id' => $group->id,
            'home_registration_id' => Registration::factory()->create(['tournament_id' => $other->id, 'group_id' => $group->id])->id,
            'away_registration_id' => Registration::factory()->create(['tournament_id' => $other->id, 'group_id' => $group->id])->id,
            'phase' => 'regular_stage', 'matchday' => 1, 'round_number' => 1, 'status' => MatchStatus::SCHEDULED,
            'match_datetime' => '2026-11-07 09:00:00', 'field_id' => $this->fieldA->id,
        ])->save();

        $this->postJson($this->api("tournaments/{$this->tournament->id}/schedule/generate"), [], $this->header)->assertOk();

        $this->assertSame('2026-11-07 08:00', $locked->refresh()->match_datetime?->format('Y-m-d H:i'));
        $this->assertSame($this->referee->id, $locked->referee_user_id);
        $this->assertNull($free->refresh()->referee_user_id);
        $this->assertNull($free->original_match_datetime);
        $this->assertSame(0, GameMatch::query()->where('tournament_id', $this->tournament->id)->where('id', '!=', $locked->id)
            ->whereDate('match_datetime', '2026-11-07')->whereTime('match_datetime', '09:00:00')->count());
    }

    public function test_delete_schedule_clears_referees_but_keeps_locked_matches(): void
    {
        [$locked, $free] = $this->matches;
        $this->place($locked, '2026-11-07 08:00:00', $this->fieldA, $this->referee);
        $free->forceFill(['match_datetime' => '2026-11-07 10:00:00', 'field_id' => $this->fieldB->id, 'referee_user_id' => $this->otherReferee->id])->save();

        $this->deleteJson($this->api("tournaments/{$this->tournament->id}/schedule"), [], $this->header)->assertOk()->assertJsonPath('cleared', 1);

        $this->assertSame($this->referee->id, $locked->refresh()->referee_user_id);
        $this->assertNull($free->refresh()->referee_user_id);
        $this->assertNull($free->match_datetime);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function endpoints(): array
    {
        return [
            'update' => ['PATCH', 'schedule'],
            'swap' => ['POST', 'swap-schedule'],
            'slots' => ['GET', 'available-slots'],
            'referees' => ['GET', 'available-referees'],
        ];
    }

    /**
     * @dataProvider endpoints
     */
    public function test_roles_and_foreign_leagues(string $method, string $suffix): void
    {
        $url = fn (League $league) => "/api/leagues/{$league->id}/matches/{$this->matches[0]->id}/{$suffix}";

        $this->app->make('auth')->forgetGuards();
        $this->json($method, $url($this->league))->assertUnauthorized();

        foreach ([RoleEnum::REFEREE, RoleEnum::PLAYER] as $role) {
            $user = User::factory()->create();
            $user->roles()->attach($this->createRole($role));
            $this->json($method, $url($this->league), [], $this->authHeader($user->createToken('t')->plainTextToken))->assertForbidden();
        }

        // Liga ajena: 403 como en el resto de la API; un partido de otra liga no se alcanza desde la propia: 404.
        $foreign = League::factory()->create();
        $this->app->make('auth')->forgetGuards();
        $this->json($method, $url($foreign), [], $this->header)->assertForbidden();

        $mine = $this->leagueWithAdminHeader();
        $this->app->make('auth')->forgetGuards();
        $this->json($method, "/api/leagues/{$mine[0]->id}/matches/{$this->matches[0]->id}/{$suffix}", ['with_match_id' => $this->matches[1]->id], $mine[1])->assertNotFound();
    }
}
