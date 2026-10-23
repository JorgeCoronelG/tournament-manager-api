<?php

namespace Tests\Feature\Tournaments;

use App\Contracts\Services\RegistrationServiceInterface;
use App\Core\Enum\Message;
use App\Core\Enum\Tiebreaker;
use App\Core\Enum\TournamentGroupType;
use App\Core\Enum\TournamentStatus;
use App\Exceptions\CustomErrorException;
use App\Models\League;
use App\Models\Registration;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Registrations\InteractsWithRegistrations;
use Tests\TestCase;

class TournamentConfigTest extends TestCase
{
    use InteractsWithRegistrations, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge(['name' => 'Copa Apertura', 'gender' => 'mixed', 'start_date' => '2026-11-01'], $overrides);
    }

    private function url(League $league, ?Tournament $tournament = null): string
    {
        return $tournament === null
            ? "/api/leagues/{$league->id}/tournaments"
            : "/api/leagues/{$league->id}/tournaments/{$tournament->id}";
    }

    public function test_creates_with_defaults_and_a_single_regular_stage_group(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();

        $id = $this->postJson($this->url($league), $this->payload(), $header)
            ->assertCreated()
            ->assertJsonPath('rounds', 1)
            ->assertJsonPath('match_duration_minutes', 60)
            ->assertJsonPath('tiebreakers', ['points', 'goal_difference', 'goals_for', 'head_to_head'])
            ->json('id');

        $groups = TournamentGroup::query()->where('tournament_id', $id)->get();

        $this->assertCount(1, $groups);
        $this->assertSame('Fase regular', $groups[0]->name);
        $this->assertSame(TournamentGroupType::REGULAR_STAGE, $groups[0]->type);
    }

    public function test_creates_with_custom_values(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();

        $this->postJson($this->url($league), $this->payload([
            'rounds' => 2, 'match_duration_minutes' => 50, 'tiebreakers' => ['wins', 'points'],
        ]), $header)
            ->assertCreated()
            ->assertJsonPath('rounds', 2)
            ->assertJsonPath('match_duration_minutes', 50)
            ->assertJsonPath('tiebreakers', ['wins', 'points']);
    }

    /**
     * @return array<string, array{0: string, 1: mixed, 2?: string}>
     */
    public static function invalid(): array
    {
        return [
            'rounds three' => ['rounds', 3],
            'rounds zero' => ['rounds', 0],
            'rounds text' => ['rounds', 'abc'],
            'duration low' => ['match_duration_minutes', 19],
            'duration high' => ['match_duration_minutes', 181],
            'duration text' => ['match_duration_minutes', 'abc'],
            'tiebreakers empty' => ['tiebreakers', []],
            'tiebreakers text' => ['tiebreakers', 'points'],
            'tiebreakers repeated' => ['tiebreakers', ['points', 'points'], 'tiebreakers.0'],
            'tiebreakers unknown' => ['tiebreakers', ['points', 'luck'], 'tiebreakers.1'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_rejects_invalid_values_on_create_and_edit(string $field, mixed $value, ?string $errorKey = null): void
    {
        $errorKey ??= $field;
        [$league, $header] = $this->leagueWithAdminHeader();
        $tournament = Tournament::factory()->create(['league_id' => $league->id]);

        $this->postJson($this->url($league), $this->payload([$field => $value]), $header)
            ->assertStatus(422)->assertJsonStructure(['error' => [$errorKey]]);
        $this->putJson($this->url($league, $tournament), $this->payload([$field => $value]), $header)
            ->assertStatus(422)->assertJsonStructure(['error' => [$errorKey]]);
    }

    public function test_planning_edits_all_three_and_omitted_fields_are_kept(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $tournament = Tournament::factory()->create(['league_id' => $league->id]);

        $this->putJson($this->url($league, $tournament), $this->payload([
            'rounds' => 2, 'match_duration_minutes' => 90, 'tiebreakers' => ['goals_against', 'wins'],
        ]), $header)->assertOk()->assertJsonPath('rounds', 2)->assertJsonPath('tiebreakers', ['goals_against', 'wins']);

        $this->putJson($this->url($league, $tournament), $this->payload(['name' => 'Otro nombre']), $header)
            ->assertOk()
            ->assertJsonPath('rounds', 2)
            ->assertJsonPath('match_duration_minutes', 90)
            ->assertJsonPath('tiebreakers', ['goals_against', 'wins']);
    }

    /**
     * @return array<string, mixed>
     */
    private function inProgressBody(Tournament $tournament, array $overrides = []): array
    {
        return $this->payload(array_merge([
            'name' => $tournament->name, 'gender' => 'mixed', 'min_age' => null, 'max_age' => null,
            'start_date' => $tournament->start_date->format('Y-m-d'),
        ], $overrides));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function configChanges(): array
    {
        return [
            'rounds' => [['rounds' => 2]],
            'rounds as text' => [['rounds' => '2']],
            'match duration' => [['match_duration_minutes' => 45]],
            'tiebreakers' => [['tiebreakers' => ['wins']]],
            'tiebreakers reordered' => [['tiebreakers' => ['goal_difference', 'points', 'goals_for', 'head_to_head']]],
        ];
    }

    #[DataProvider('configChanges')]
    public function test_in_progress_rejects_changing_any_of_the_three_fields(array $change): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $tournament = Tournament::factory()->status(TournamentStatus::IN_PROGRESS)->create(['league_id' => $league->id]);

        $this->putJson($this->url($league, $tournament), $this->inProgressBody($tournament, $change), $header)
            ->assertStatus(422)->assertJsonPath('error', 'Con el torneo en curso solo se puede editar el nombre.');
        $this->assertSame(1, $tournament->fresh()->rounds);
        $this->assertSame(60, $tournament->fresh()->match_duration_minutes);
    }

    public function test_in_progress_changes_only_the_name_and_ignores_equal_values(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $tournament = Tournament::factory()->status(TournamentStatus::IN_PROGRESS)->create(['league_id' => $league->id]);

        $this->putJson($this->url($league, $tournament), $this->inProgressBody($tournament, [
            'name' => 'Nuevo nombre', 'rounds' => '1', 'match_duration_minutes' => 60,
            'tiebreakers' => ['points', 'goal_difference', 'goals_for', 'head_to_head'],
        ]), $header)->assertOk()->assertJsonPath('name', 'Nuevo nombre')->assertJsonPath('rounds', 1);
    }

    public function test_tiebreakers_without_points_are_saved_in_the_exact_order(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $tournament = Tournament::factory()->create(['league_id' => $league->id]);

        $this->putJson($this->url($league, $tournament), $this->payload(['tiebreakers' => ['head_to_head', 'goals_for']]), $header)
            ->assertOk()->assertJsonPath('tiebreakers', ['head_to_head', 'goals_for']);
        $this->assertSame(['head_to_head', 'goals_for'], $tournament->fresh()->tiebreakers);
    }

    public function test_finished_tournament_edits_nothing(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $tournament = Tournament::factory()->status(TournamentStatus::FINISHED)->create(['league_id' => $league->id]);

        $this->putJson($this->url($league, $tournament), $this->payload(['rounds' => 2]), $header)
            ->assertStatus(422)->assertJsonPath('error', Message::TOURNAMENT_FINISHED_NOT_EDITABLE);
        $this->assertSame(1, $tournament->fresh()->rounds);
    }

    public function test_registering_assigns_the_single_regular_group_or_none_when_there_are_several(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $first = Team::factory()->create(['league_id' => $league->id]);
        $second = Team::factory()->create(['league_id' => $league->id]);
        $group = $tournament->groups()->firstOrFail();

        $this->postJson($this->registrationsUrl($league, $tournament), ['team_ids' => [$first->id]], $header)->assertCreated();
        $this->assertSame($group->id, Registration::query()->where('team_id', $first->id)->value('group_id'));

        $extra = new TournamentGroup;
        $extra->forceFill(['tournament_id' => $tournament->id, 'name' => 'Grupo B', 'type' => TournamentGroupType::REGULAR_STAGE])->save();

        $this->postJson($this->registrationsUrl($league, $tournament), ['team_ids' => [$second->id]], $header)->assertCreated();
        $this->assertNull(Registration::query()->where('team_id', $second->id)->value('group_id'));
    }

    public function test_assign_group_must_be_a_regular_group_of_the_same_tournament(): void
    {
        $registration = Registration::factory()->create();
        $service = app(RegistrationServiceInterface::class);
        $other = Tournament::factory()->create();
        $playoff = new TournamentGroup;
        $playoff->forceFill(['tournament_id' => $registration->tournament_id, 'name' => 'Liguilla', 'type' => TournamentGroupType::PLAYOFF])->save();
        $regular = new TournamentGroup;
        $regular->forceFill(['tournament_id' => $registration->tournament_id, 'name' => 'Grupo B', 'type' => TournamentGroupType::REGULAR_STAGE])->save();

        foreach ([$playoff->id, $other->groups()->firstOrFail()->id] as $invalid) {
            try {
                $service->assignGroup($registration, $invalid);
                $this->fail('Debió rechazar el grupo.');
            } catch (CustomErrorException $e) {
                $this->assertSame(Message::REGISTRATION_GROUP_INVALID, $e->getMessage());
            }
        }

        $service->assignGroup($registration, $regular->id);
        $this->assertSame($regular->id, $registration->fresh()->group_id);
    }

    public function test_cannot_start_with_active_registrations_without_group(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $tournament = Tournament::factory()->create(['league_id' => $league->id]);
        Registration::factory()->count(2)->create(['tournament_id' => $tournament->id]);
        $withoutGroup = Registration::factory()->create(['tournament_id' => $tournament->id]);
        $withoutGroup->forceFill(['group_id' => null])->save();
        $url = $this->url($league, $tournament).'/status';

        $this->patchJson($url, ['status' => 'in_progress'], $header)
            ->assertStatus(422)->assertJsonPath('error', Message::TOURNAMENT_START_NEEDS_GROUPS);
        $this->assertSame(TournamentStatus::PLANNING, $tournament->fresh()->status);

        $withoutGroup->forceFill(['group_id' => $tournament->groups()->value('id')])->save();

        $this->patchJson($url, ['status' => 'in_progress'], $header)->assertOk();
    }

    public function test_tiebreaker_catalog(): void
    {
        $this->assertSame(
            ['points', 'goal_difference', 'goals_for', 'goals_against', 'head_to_head', 'wins'],
            Tiebreaker::values()
        );
    }
}
