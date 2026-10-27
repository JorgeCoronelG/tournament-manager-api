<?php

namespace Tests\Feature\Schedule;

use App\Core\Enum\FieldStatus;
use App\Core\Enum\Role as RoleEnum;
use App\Core\Enum\TournamentStatus;
use App\Models\Field;
use App\Models\GameMatch;
use App\Models\League;
use App\Models\LeagueReferee;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Groups\InteractsWithGroups;
use Tests\TestCase;

class AgendaTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();

        [$this->league, $this->tournament, $this->header] = $this->tournamentWithAdminHeader();
        $this->teamsIn($this->tournament, $this->tournament->groups()->firstOrFail(), 4);
        $this->tournament->forceFill(['status' => TournamentStatus::IN_PROGRESS])->save();
        $this->postJson("/api/leagues/{$this->league->id}/tournaments/{$this->tournament->id}/matches/generate", ['shuffle' => false], $this->header)->assertOk();
        $this->matches = GameMatch::query()->orderBy('id')->get()->all();

        $this->fieldA = Field::factory()->create(['league_id' => $this->league->id, 'name' => 'Cancha 1']);
        $this->fieldB = Field::factory()->create(['league_id' => $this->league->id, 'name' => 'Cancha 2']);
        Field::factory()->status(FieldStatus::INACTIVE)->create(['league_id' => $this->league->id]);
        $this->referee = User::factory()->create();
        $this->referee->roles()->attach($this->createRole(RoleEnum::REFEREE));
        LeagueReferee::factory()->create(['league_id' => $this->league->id, 'user_id' => $this->referee->id]);

        $this->place(0, '2026-11-07 10:00:00', $this->fieldB, $this->referee);
        $this->place(1, '2026-11-07 09:00:00', $this->fieldA);
        $this->place(2, '2026-11-14 09:00:00', $this->fieldA);
    }

    private function api(string $query = ''): string
    {
        return "/api/leagues/{$this->league->id}/agenda{$query}";
    }

    private function place(int $index, string $at, Field $field, ?User $referee = null): void
    {
        $this->matches[$index]->forceFill(['match_datetime' => $at, 'field_id' => $field->id, 'referee_user_id' => $referee?->id])->save();
    }

    public function test_lists_days_ordered_by_time_with_the_match_data_and_the_active_fields(): void
    {
        $response = $this->getJson($this->api('?date_from=2026-11-01&date_to=2026-11-30'), $this->header)
            ->assertOk()
            ->assertJsonCount(2, 'days')
            ->assertJsonPath('days.0.date', '2026-11-07')
            ->assertJsonPath('days.1.date', '2026-11-14')
            ->assertJsonCount(2, 'fields')
            ->assertJsonPath('days.0.matches.0.id', $this->matches[1]->id)
            ->assertJsonPath('days.0.matches.1.id', $this->matches[0]->id)
            ->assertJsonPath('days.0.matches.0.phase', 'regular')
            ->assertJsonPath('days.0.matches.0.round_label', 'Jornada '.$this->matches[1]->matchday)
            ->assertJsonPath('days.0.matches.1.referee.id', $this->referee->id)
            ->assertJsonPath('days.0.matches.1.tournament.id', $this->tournament->id);

        $this->assertNotNull($response->json('days.0.matches.0.end_datetime'));
        $this->assertSame(['Cancha 1', 'Cancha 2'], array_column((array) $response->json('fields'), 'name'));
    }

    public function test_filters_by_tournament_field_and_referee(): void
    {
        $range = '?date_from=2026-11-01&date_to=2026-11-30';

        $this->getJson($this->api($range.'&field_id='.$this->fieldA->id), $this->header)->assertOk()->assertJsonCount(2, 'days')->assertJsonCount(1, 'days.0.matches');
        $this->getJson($this->api($range.'&referee_user_id='.$this->referee->id), $this->header)->assertOk()->assertJsonCount(1, 'days')->assertJsonPath('days.0.matches.0.id', $this->matches[0]->id);
        $this->getJson($this->api($range.'&unassigned_referee=1'), $this->header)->assertOk()->assertJsonCount(2, 'days');
        $this->getJson($this->api($range.'&tournament_id='.$this->tournament->id), $this->header)->assertOk()->assertJsonCount(2, 'days');

        $foreign = Tournament::factory()->create();
        $this->getJson($this->api($range.'&tournament_id='.$foreign->id), $this->header)->assertNotFound();
    }

    public function test_range_defaults_to_the_current_week_and_is_limited_to_31_days(): void
    {
        $this->getJson($this->api(), $this->header)->assertOk()->assertJsonPath('days', [])
            ->assertJsonPath('date_from', now()->startOfWeek()->toDateString());

        $this->getJson($this->api('?date_from=2026-11-01&date_to=2026-12-01'), $this->header)->assertOk();
        $this->getJson($this->api('?date_from=2026-11-01&date_to=2026-12-02'), $this->header)->assertUnprocessable();
        $this->getJson($this->api('?date_from=2026-11-10&date_to=2026-11-01'), $this->header)->assertUnprocessable();
    }

    public function test_counts_matches_without_time(): void
    {
        $this->getJson($this->api('/unscheduled'), $this->header)->assertOk()->assertJsonPath('unscheduled', 3);
        $this->getJson($this->api('/unscheduled?tournament_id='.$this->tournament->id), $this->header)->assertOk()->assertJsonPath('unscheduled', 3);
    }

    public function test_roles_and_foreign_leagues(): void
    {
        $this->app->make('auth')->forgetGuards();
        $this->getJson($this->api())->assertUnauthorized();

        foreach ([RoleEnum::REFEREE, RoleEnum::PLAYER] as $role) {
            $user = User::factory()->create();
            $user->roles()->attach($this->createRole($role));
            $this->app->make('auth')->forgetGuards();
            $this->getJson($this->api(), $this->authHeader($user->createToken('t')->plainTextToken))->assertForbidden();
        }

        $foreign = League::factory()->create();
        $this->app->make('auth')->forgetGuards();
        $this->getJson("/api/leagues/{$foreign->id}/agenda", $this->header)->assertForbidden();
    }
}
