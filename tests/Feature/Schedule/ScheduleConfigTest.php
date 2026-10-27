<?php

namespace Tests\Feature\Schedule;

use App\Core\Enum\FieldStatus;
use App\Core\Enum\Message;
use App\Core\Enum\TournamentStatus;
use App\Models\Field;
use App\Models\League;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Tournaments\InteractsWithTournaments;
use Tests\TestCase;

class ScheduleConfigTest extends TestCase
{
    use InteractsWithTournaments, RefreshDatabase;

    private function url(League $league, Tournament $tournament, string $suffix = ''): string
    {
        return "/api/leagues/{$league->id}/tournaments/{$tournament->id}/schedule-config{$suffix}";
    }

    private function assertErrorMessage(string $message, TestResponse $response): void
    {
        $this->assertContains($message, array_merge(...array_values((array) $response->json('error'))));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function tournament(League $league, array $overrides = [], TournamentStatus $status = TournamentStatus::PLANNING): Tournament
    {
        return Tournament::factory()->status($status)->create(['league_id' => $league->id, 'match_duration_minutes' => 60] + $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function config(Field $field, array $overrides = []): array
    {
        return array_merge([
            'schedule_start_date' => '2026-11-02',
            'windows' => [['weekday' => 6, 'field_id' => $field->id, 'start_time' => '08:00', 'end_time' => '12:00']],
            'blocks' => [],
        ], $overrides);
    }

    public function test_saves_and_reads_a_config_with_a_split_field(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $tournament = $this->tournament($league);
        $field = Field::factory()->create(['league_id' => $league->id]);

        $payload = $this->config($field, ['windows' => [
            ['weekday' => 6, 'field_id' => $field->id, 'start_time' => '10:00', 'end_time' => '14:00'],
            ['weekday' => 6, 'field_id' => $field->id, 'start_time' => '08:00', 'end_time' => '09:00'],
        ], 'blocks' => [['date' => '2026-11-14', 'field_id' => null, 'reason' => 'Festivo']]]);

        $this->putJson($this->url($league, $tournament), $payload, $header)->assertOk();

        $this->getJson($this->url($league, $tournament), $header)
            ->assertOk()
            ->assertJsonPath('schedule_start_date', '2026-11-02')
            ->assertJsonPath('match_duration_minutes', 60)
            ->assertJsonPath('windows.0.start_time', '08:00')
            ->assertJsonPath('windows.1.start_time', '10:00')
            ->assertJsonPath('windows.0.field.id', $field->id)
            ->assertJsonPath('blocks.0.date', '2026-11-14')
            ->assertJsonPath('blocks.0.start_time', null);
    }

    public function test_capacity_counts_slots_per_duration_and_flags_outdated_matches(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $tournament = $this->tournament($league);
        $field = Field::factory()->create(['league_id' => $league->id]);

        $this->putJson($this->url($league, $tournament), $this->config($field), $header)->assertOk();

        $this->getJson($this->url($league, $tournament, '/capacity'), $header)
            ->assertOk()
            ->assertJsonMissingPath('weeks')
            ->assertJsonPath('matchdays', [])
            ->assertJsonPath('has_calendar', false)
            ->assertJsonPath('schedule_outdated', false)
            ->assertJsonPath('warnings', []);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidConfigs(): array
    {
        return [
            'inicio mayor que fin' => [['windows' => [['start_time' => '12:00', 'end_time' => '08:00']]], Message::SCHEDULE_TIME_ORDER],
            'ventana corta' => [['windows' => [['start_time' => '08:00', 'end_time' => '08:30']]], Message::SCHEDULE_WINDOW_TOO_SHORT],
            'sin fecha de inicio' => [['schedule_start_date' => null], Message::SCHEDULE_START_DATE_REQUIRED],
            'bloqueo con una sola hora' => [['blocks' => [['date' => '2026-11-14', 'start_time' => '08:00']]], Message::SCHEDULE_BLOCK_TIME_PAIR],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     *
     * @dataProvider invalidConfigs
     */
    public function test_rejects_invalid_configs(array $overrides, string $message): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $tournament = $this->tournament($league);
        $field = Field::factory()->create(['league_id' => $league->id]);
        $payload = $this->config($field);

        if (isset($overrides['windows'])) {
            $overrides['windows'] = [array_merge($payload['windows'][0], $overrides['windows'][0])];
        }

        $response = $this->putJson($this->url($league, $tournament), array_merge($payload, $overrides), $header)->assertUnprocessable();

        $this->assertErrorMessage($message, $response);
    }

    public function test_rejects_overlaps_and_unavailable_fields(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $tournament = $this->tournament($league);
        $field = Field::factory()->create(['league_id' => $league->id]);
        $inactive = Field::factory()->status(FieldStatus::INACTIVE)->create(['league_id' => $league->id]);
        $foreign = Field::factory()->create();
        $window = fn (int $fieldId, string $start, string $end) => ['weekday' => 6, 'field_id' => $fieldId, 'start_time' => $start, 'end_time' => $end];

        $overlap = $this->config($field, ['windows' => [$window($field->id, '08:00', '10:00'), $window($field->id, '09:00', '11:00')]]);
        $this->assertErrorMessage(Message::SCHEDULE_WINDOWS_OVERLAP, $this->putJson($this->url($league, $tournament), $overlap, $header)->assertUnprocessable());

        // Ventanas pegadas (una termina cuando empieza la otra) sí se permiten.
        $touching = $this->config($field, ['windows' => [$window($field->id, '08:00', '10:00'), $window($field->id, '10:00', '12:00')]]);
        $this->putJson($this->url($league, $tournament), $touching, $header)->assertOk();

        foreach ([$inactive, $foreign] as $unavailable) {
            $response = $this->putJson($this->url($league, $tournament), $this->config($unavailable), $header)->assertUnprocessable();
            $this->assertErrorMessage(Message::SCHEDULE_FIELD_UNAVAILABLE, $response);
        }
    }

    public function test_cannot_be_modified_in_playoffs_or_finished(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $field = Field::factory()->create(['league_id' => $league->id]);

        foreach ([TournamentStatus::PLAYOFFS, TournamentStatus::FINISHED] as $status) {
            $this->putJson($this->url($league, $this->tournament($league, [], $status)), $this->config($field), $header)
                ->assertUnprocessable()
                ->assertJsonPath('error', Message::SCHEDULE_NOT_EDITABLE);
        }
    }

    public function test_capacity_preview_uses_the_given_config_without_saving_it(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $tournament = $this->tournament($league);
        $field = Field::factory()->create(['league_id' => $league->id]);
        $config = $this->config($field);

        $this->postJson($this->url($league, $tournament, '/capacity'), $config, $header)
            ->assertOk()
            ->assertJsonPath('match_duration_minutes', 60)
            ->assertJsonPath('schedule_start_date', $config['schedule_start_date']);

        $this->getJson($this->url($league, $tournament), $header)
            ->assertOk()
            ->assertJsonPath('windows', []);
    }
}
