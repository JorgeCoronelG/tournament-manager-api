<?php

namespace Tests\Feature\Fields;

use App\Core\Enum\MatchStatus;
use App\Core\Enum\Message;
use App\Core\Enum\TournamentStatus;
use App\Models\Field;
use App\Models\GameMatch;
use App\Models\League;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Groups\InteractsWithGroups;
use Tests\TestCase;

class DeleteFieldValidationTest extends TestCase
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

    private function destroy(?Field $field = null): TestResponse
    {
        $field ??= $this->field;

        return $this->deleteJson("/api/leagues/{$field->league_id}/fields/{$field->id}", [], $this->header);
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

    private function setStatus(TournamentStatus $status): void
    {
        $this->tournament->forceFill(['status' => $status])->save();
    }

    private function addWindow(): void
    {
        DB::table('tournament_schedule_windows')->insert([
            'tournament_id' => $this->tournament->id, 'weekday' => 6, 'field_id' => $this->field->id,
            'start_time' => '08:00', 'end_time' => '12:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function addBlock(): void
    {
        DB::table('tournament_schedule_blocks')->insert([
            'tournament_id' => $this->tournament->id, 'date' => '2026-11-07', 'field_id' => $this->field->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assertBlocked(string $message): void
    {
        $this->destroy()->assertStatus(422)->assertExactJson(['code' => 422, 'error' => $message]);
        $this->assertNotSoftDeleted($this->field);
    }

    public function test_deletes_a_field_with_nothing_linked(): void
    {
        $this->destroy()->assertNoContent();

        $this->assertSoftDeleted($this->field);
    }

    public function test_a_scheduled_match_blocks_the_deletion(): void
    {
        $this->matchOnField();

        $this->assertBlocked('La cancha <b>Cancha 1</b> tiene partidos programados. Reasígnalos a otra cancha o quítales el horario antes de eliminarla.');
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
    public function test_played_or_cancelled_matches_do_not_block_and_keep_showing_the_field(MatchStatus $status): void
    {
        $this->matchOnField($status);

        $this->destroy()->assertNoContent();

        $this->assertSoftDeleted($this->field);
        $this->getJson("/api/leagues/{$this->league->id}/agenda?date_from=2026-11-01&date_to=2026-11-30", $this->header)
            ->assertOk()
            ->assertJsonPath('days.0.matches.0.field.name', 'Cancha 1');
    }

    public function test_a_soft_deleted_scheduled_match_does_not_block(): void
    {
        $this->matchOnField()->delete();

        $this->destroy()->assertNoContent();
    }

    /**
     * @return array<string, array{0: TournamentStatus}>
     */
    public static function openStatuses(): array
    {
        return [
            'planning' => [TournamentStatus::PLANNING],
            'in_progress' => [TournamentStatus::IN_PROGRESS],
            'playoffs' => [TournamentStatus::PLAYOFFS],
        ];
    }

    /**
     * @dataProvider openStatuses
     */
    public function test_a_window_of_an_unfinished_tournament_blocks_the_deletion(TournamentStatus $status): void
    {
        $this->setStatus($status);
        $this->addWindow();

        $this->assertBlocked('La cancha <b>Cancha 1</b> está en la configuración de horarios de un torneo que no ha terminado. Quítala de esa configuración antes de eliminarla.');
    }

    public function test_a_block_of_an_unfinished_tournament_blocks_the_deletion(): void
    {
        $this->addBlock();

        $this->assertBlocked(Message::getMessageFieldInOpenScheduleConfig('Cancha 1'));
    }

    public function test_the_schedule_config_of_finished_tournaments_does_not_block(): void
    {
        $this->setStatus(TournamentStatus::FINISHED);
        $this->addWindow();
        $this->addBlock();

        $this->destroy()->assertNoContent();

        $this->assertSoftDeleted($this->field);
    }

    public function test_a_scheduled_match_wins_over_the_schedule_config(): void
    {
        $this->matchOnField();
        $this->addWindow();

        $this->assertBlocked(Message::getMessageFieldHasScheduledMatches('Cancha 1'));
    }

    public function test_a_field_of_another_league_responds_404_and_is_kept(): void
    {
        $foreign = Field::factory()->create(['league_id' => League::factory()->create()->id]);

        $this->deleteJson("/api/leagues/{$this->league->id}/fields/{$foreign->id}", [], $this->header)->assertStatus(404);

        $this->assertNotSoftDeleted($foreign);
    }

    public function test_the_field_name_is_escaped_in_the_message(): void
    {
        $this->field->forceFill(['name' => '<script>x</script>'])->save();
        $this->addWindow();

        $error = $this->destroy()->assertStatus(422)->json('error');

        $this->assertStringContainsString('<b>&lt;script&gt;x&lt;/script&gt;</b>', $error);
        $this->assertStringNotContainsString('<script>', $error);
    }
}
