<?php

namespace Tests\Feature\Fields;

use App\Core\Enum\FieldStatus;
use App\Core\Enum\MatchStatus;
use App\Core\Enum\TournamentGroupType;
use App\Core\Enum\TournamentStatus;
use App\Models\Field;
use App\Models\GameMatch;
use App\Models\League;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Groups\InteractsWithGroups;
use Tests\TestCase;

class DeactivateFieldValidationTest extends TestCase
{
    use InteractsWithGroups, RefreshDatabase;

    private League $league;

    private Tournament $tournament;

    /** @var array<string, string> */
    private array $header;

    private Field $field;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->league, $this->tournament, $this->header] = $this->tournamentWithAdminHeader();
        $this->field = Field::factory()->create(['league_id' => $this->league->id, 'name' => 'Cancha 1']);
    }

    private function setStatus(string $status, ?Field $field = null): TestResponse
    {
        $field ??= $this->field;

        return $this->patchJson("/api/leagues/{$this->league->id}/fields/{$field->id}/status", ['status' => $status], $this->header);
    }

    private function matchOnField(MatchStatus $status = MatchStatus::SCHEDULED): GameMatch
    {
        $this->teamsIn($this->tournament, $this->tournament->groups()->firstOrFail(), 2);
        $this->tournament->forceFill(['status' => TournamentStatus::IN_PROGRESS])->save();
        $this->postJson("/api/leagues/{$this->league->id}/tournaments/{$this->tournament->id}/matches/generate", ['shuffle' => false], $this->header)->assertOk();

        $match = GameMatch::query()->firstOrFail();
        $match->forceFill(['field_id' => $this->field->id, 'match_datetime' => '2026-11-07 10:00:00', 'status' => $status])->save();

        return $match;
    }

    private function assertStillActive(): void
    {
        $this->assertSame(FieldStatus::ACTIVE, $this->field->fresh()->status);
    }

    public function test_deactivates_a_field_without_matches(): void
    {
        $this->setStatus('inactive')->assertOk()->assertJsonPath('status', 'inactive');
    }

    public function test_a_scheduled_match_blocks_with_the_exact_message(): void
    {
        $this->matchOnField();

        $this->setStatus('inactive')->assertStatus(422)->assertExactJson([
            'code' => 422,
            'error' => 'La cancha <b>Cancha 1</b> tiene partidos programados. Reasígnalos a otra cancha o quítales el horario antes de darla de baja.',
        ]);
        $this->assertStillActive();
    }

    /**
     * @return array<string, array{0: MatchStatus}>
     */
    public static function closedStatuses(): array
    {
        return ['played' => [MatchStatus::PLAYED], 'cancelled' => [MatchStatus::CANCELLED]];
    }

    /**
     * @dataProvider closedStatuses
     */
    public function test_played_or_cancelled_matches_do_not_block(MatchStatus $status): void
    {
        $this->matchOnField($status);

        $this->setStatus('inactive')->assertOk();
    }

    public function test_a_soft_deleted_scheduled_match_does_not_block(): void
    {
        $this->matchOnField()->delete();

        $this->setStatus('inactive')->assertOk();
    }

    public function test_a_scheduled_playoff_match_blocks(): void
    {
        $match = $this->matchOnField();
        $playoff = TournamentGroup::query()->forceCreate(['tournament_id' => $this->tournament->id, 'name' => 'Liguilla', 'type' => TournamentGroupType::PLAYOFF]);
        $match->forceFill(['group_id' => $playoff->id])->save();

        $this->setStatus('inactive')->assertStatus(422);
        $this->assertStillActive();
    }

    public function test_activating_is_always_allowed(): void
    {
        $this->matchOnField();
        $this->field->forceFill(['status' => FieldStatus::INACTIVE])->save();

        $this->setStatus('active')->assertOk()->assertJsonPath('status', 'active');
    }

    public function test_deactivating_an_already_inactive_field_is_not_validated(): void
    {
        $this->matchOnField();
        $this->field->forceFill(['status' => FieldStatus::INACTIVE])->save();

        $this->setStatus('inactive')->assertOk();
    }

    public function test_a_field_of_another_league_responds_404(): void
    {
        $foreign = Field::factory()->create(['league_id' => League::factory()->create()->id]);

        $this->setStatus('inactive', $foreign)->assertStatus(404);

        $this->assertSame(FieldStatus::ACTIVE, $foreign->fresh()->status);
    }

    public function test_the_field_name_is_escaped_in_the_message(): void
    {
        $this->field->forceFill(['name' => '<script>x</script>'])->save();
        $this->matchOnField();

        $error = $this->setStatus('inactive')->assertStatus(422)->json('error');

        $this->assertStringContainsString('<b>&lt;script&gt;x&lt;/script&gt;</b>', $error);
        $this->assertStringNotContainsString('<script>', $error);
    }
}
