<?php

namespace Tests\Feature\Groups;

use App\Core\Enum\Message;
use App\Core\Enum\TournamentGroupType;
use App\Helpers\PlayoffRounds;
use App\Models\PlayoffSource;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlayoffGroupTest extends TestCase
{
    use InteractsWithGroups, RefreshDatabase;

    public function test_creates_a_playoff_with_one_source(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $group = $tournament->groups()->firstOrFail();

        $response = $this->postJson($this->groupsUrl($league, $tournament), [
            'type' => 'playoff', 'name' => 'Liguilla', 'start_date' => '2026-12-01', 'round_interval_days' => 7,
            'sources' => [['source_group_id' => $group->id, 'position_start' => 1, 'position_end' => 8]],
        ], $header)->assertCreated();

        $response->assertJsonPath('type', 'playoff')
            ->assertJsonPath('total_teams', 8)
            ->assertJsonPath('rounds', 3)
            ->assertJsonPath('round_names', ['Cuartos de final', 'Semifinal', 'Final'])
            ->assertJsonPath('final_date', '2026-12-15')
            ->assertJsonPath('sources.0.source_group_name', 'Fase regular');
        $this->assertDatabaseCount('playoff_sources', 1);
    }

    public function test_creates_a_playoff_with_two_sources_and_defaults(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $a = $tournament->groups()->firstOrFail();
        $b = $this->makeGroup($tournament, 'Grupo B');

        $this->postJson($this->groupsUrl($league, $tournament), [
            'type' => 'playoff', 'name' => 'Liguilla',
            'sources' => [
                ['source_group_id' => $a->id, 'position_start' => 1, 'position_end' => 2],
                ['source_group_id' => $b->id, 'position_start' => 1, 'position_end' => 2],
            ],
        ], $header)
            ->assertCreated()
            ->assertJsonPath('round_interval_days', 7)
            ->assertJsonPath('start_date', null)
            ->assertJsonPath('final_date', null)
            ->assertJsonPath('total_teams', 4)
            ->assertJsonPath('round_names', ['Semifinal', 'Final']);
    }

    /**
     * @return array<string, array{0: list<array{0: int, 1: int}>, 1: int}>
     */
    public static function totals(): array
    {
        return [
            'three' => [[[1, 3]], 3],
            'five' => [[[1, 2], [4, 6]], 5],
            'six' => [[[1, 6]], 6],
            'one' => [[[2, 2]], 1],
        ];
    }

    /**
     * @param  list<array{0: int, 1: int}>  $ranges
     */
    #[DataProvider('totals')]
    public function test_total_must_be_a_power_of_two(array $ranges, int $total): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $group = $tournament->groups()->firstOrFail();

        $this->postJson($this->groupsUrl($league, $tournament), [
            'type' => 'playoff', 'name' => 'Liguilla',
            'sources' => array_map(fn (array $r) => ['source_group_id' => $group->id, 'position_start' => $r[0], 'position_end' => $r[1]], $ranges),
        ], $header)->assertStatus(422)->assertJsonPath('error.sources.0', Message::getMessagePlayoffTotalTeams($total));
    }

    public function test_validates_the_source_fields(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $group = $tournament->groups()->firstOrFail();
        $foreign = Tournament::factory()->create()->groups()->firstOrFail();
        $playoff = $this->makeGroup($tournament, 'Otra liguilla', TournamentGroupType::PLAYOFF);
        $url = $this->groupsUrl($league, $tournament);
        $post = fn (array $sources) => $this->postJson($url, ['type' => 'playoff', 'name' => 'Liguilla', 'sources' => $sources], $header);

        $post([])->assertStatus(422)->assertJsonStructure(['error' => ['sources']]);
        $post([['source_group_id' => $group->id, 'position_start' => 0, 'position_end' => 2]])->assertStatus(422)->assertJsonStructure(['error' => ['sources.0.position_start']]);
        $post([['source_group_id' => $group->id, 'position_start' => 3, 'position_end' => 2]])->assertStatus(422)->assertJsonStructure(['error' => ['sources.0.position_end']]);
        $post([['source_group_id' => $foreign->id, 'position_start' => 1, 'position_end' => 2]])->assertStatus(422);
        $this->assertSame(Message::PLAYOFF_SOURCE_INVALID_GROUP, $post([['source_group_id' => $foreign->id, 'position_start' => 1, 'position_end' => 2]])->json('error')['sources.0.source_group_id'][0]);
        $post([['source_group_id' => $playoff->id, 'position_start' => 1, 'position_end' => 2]])->assertStatus(422)->assertJsonStructure(['error' => ['sources.0.source_group_id']]);
        $this->postJson($url, ['type' => 'playoff', 'name' => 'Liguilla', 'round_interval_days' => 0, 'sources' => []], $header)->assertStatus(422);
    }

    public function test_ranges_cannot_overlap_within_a_playoff_or_with_other_playoffs(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $a = $tournament->groups()->firstOrFail();
        $b = $this->makeGroup($tournament, 'Grupo B');
        $url = $this->groupsUrl($league, $tournament);
        $source = fn ($group, int $start, int $end) => ['source_group_id' => $group->id, 'position_start' => $start, 'position_end' => $end];

        $this->postJson($url, ['type' => 'playoff', 'name' => 'Interna', 'sources' => [$source($a, 1, 2), $source($a, 2, 3)]], $header)
            ->assertStatus(422)->assertJsonStructure(['error' => ['sources.0.position_start']]);

        $this->postJson($url, ['type' => 'playoff', 'name' => 'Primera', 'sources' => [$source($a, 1, 4)]], $header)->assertCreated();

        $this->postJson($url, ['type' => 'playoff', 'name' => 'Segunda', 'sources' => [$source($a, 4, 5), $source($b, 1, 1)]], $header)
            ->assertStatus(422)->assertJsonStructure(['error' => ['sources.0.position_start']]);
        // Rangos contiguos o de otro grupo sí se pueden.
        $this->postJson($url, ['type' => 'playoff', 'name' => 'Tercera', 'sources' => [$source($a, 5, 6), $source($b, 1, 2)]], $header)->assertCreated();
    }

    public function test_updates_a_playoff_and_replaces_its_sources(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $a = $tournament->groups()->firstOrFail();
        $b = $this->makeGroup($tournament, 'Grupo B');
        $url = $this->groupsUrl($league, $tournament);
        $id = $this->postJson($url, [
            'type' => 'playoff', 'name' => 'Liguilla',
            'sources' => [['source_group_id' => $a->id, 'position_start' => 1, 'position_end' => 4]],
        ], $header)->assertCreated()->json('id');

        // Reusar sus propios rangos en el reemplazo no cuenta como traslape.
        $this->patchJson("{$url}/{$id}", [
            'name' => 'Liguilla oro', 'start_date' => '2026-12-05', 'round_interval_days' => 3,
            'sources' => [
                ['source_group_id' => $a->id, 'position_start' => 1, 'position_end' => 1],
                ['source_group_id' => $b->id, 'position_start' => 1, 'position_end' => 1],
            ],
        ], $header)
            ->assertOk()
            ->assertJsonPath('name', 'Liguilla oro')
            ->assertJsonPath('total_teams', 2)
            ->assertJsonPath('round_names', ['Final'])
            ->assertJsonPath('final_date', '2026-12-05');
        $this->assertDatabaseCount('playoff_sources', 2);

        $this->patchJson("{$url}/{$id}", ['sources' => [['source_group_id' => $a->id, 'position_start' => 1, 'position_end' => 3]]], $header)->assertStatus(422);
        $this->patchJson("{$url}/{$id}", ['name' => 'Solo nombre'], $header)->assertOk();
        $this->assertDatabaseCount('playoff_sources', 2);
    }

    public function test_deleting_a_playoff_removes_its_sources(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $a = $tournament->groups()->firstOrFail();
        $id = $this->postJson($this->groupsUrl($league, $tournament), [
            'type' => 'playoff', 'name' => 'Liguilla',
            'sources' => [['source_group_id' => $a->id, 'position_start' => 1, 'position_end' => 2]],
        ], $header)->json('id');

        $this->deleteJson($this->groupsUrl($league, $tournament, "/{$id}"), [], $header)->assertNoContent();

        $this->assertSoftDeleted('tournament_groups', ['id' => $id]);
        $this->assertSame(0, PlayoffSource::query()->count());
        $this->assertNotSoftDeleted('tournament_groups', ['id' => $a->id]);
    }

    public function test_playoff_listing_comes_after_regular_groups_and_flags_misaligned_finals(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $a = $tournament->groups()->firstOrFail();
        $b = $this->makeGroup($tournament, 'Grupo B');
        $url = $this->groupsUrl($league, $tournament);
        $create = fn (string $name, string $date, int $interval, $group, int $from, int $end) => $this->postJson($url, [
            'type' => 'playoff', 'name' => $name, 'start_date' => $date, 'round_interval_days' => $interval,
            'sources' => [['source_group_id' => $group->id, 'position_start' => $from, 'position_end' => $end]],
        ], $header)->assertCreated();

        $create('Oro', '2026-12-01', 7, $a, 1, 4);
        $this->getJson($url, $header)->assertOk()->assertJsonPath('finals_aligned', true)->assertJsonPath('groups.2.name', 'Oro');

        // Su final cae el mismo día que la de Oro (1 dic + 7 = 8 dic): alineadas.
        $create('Plata', '2026-12-08', 7, $b, 1, 2);
        $this->getJson($url, $header)->assertJsonPath('finals_aligned', true);

        $create('Bronce', '2026-12-01', 7, $b, 3, 4);
        $this->getJson($url, $header)->assertJsonPath('finals_aligned', false)
            ->assertJsonPath('groups.0.type', 'regular_stage')
            ->assertJsonPath('groups.1.type', 'regular_stage')
            ->assertJsonPath('groups.4.final_date', '2026-12-01');
    }

    public function test_saves_round_dates_and_uses_them_for_rounds_detail_and_final_date(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $a = $tournament->groups()->firstOrFail();

        $this->postJson($this->groupsUrl($league, $tournament), [
            'type' => 'playoff', 'name' => 'Liguilla', 'start_date' => '2026-12-07',
            'round_dates' => ['2026-12-07', '2026-12-16', '2026-12-30'],
            'sources' => [['source_group_id' => $a->id, 'position_start' => 1, 'position_end' => 8]],
        ], $header)
            ->assertCreated()
            ->assertJsonPath('start_date', '2026-12-07')
            ->assertJsonPath('round_dates', ['2026-12-07', '2026-12-16', '2026-12-30'])
            ->assertJsonPath('rounds_detail.0', ['name' => 'Cuartos de final', 'date' => '2026-12-07'])
            ->assertJsonPath('rounds_detail.2', ['name' => 'Final', 'date' => '2026-12-30'])
            ->assertJsonPath('final_date', '2026-12-30');
    }

    public function test_rejects_wrong_count_or_unordered_round_dates(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $a = $tournament->groups()->firstOrFail();
        $url = $this->groupsUrl($league, $tournament);
        $body = fn (array $dates) => [
            'type' => 'playoff', 'name' => 'Liguilla', 'start_date' => '2026-12-01', 'round_dates' => $dates,
            'sources' => [['source_group_id' => $a->id, 'position_start' => 1, 'position_end' => 4]],
        ];

        $this->postJson($url, $body(['2026-12-01']), $header)->assertStatus(422)
            ->assertJsonPath('error.round_dates.0', Message::getMessagePlayoffRoundDatesCount(2));
        $this->postJson($url, $body(['2026-12-08', '2026-12-01']), $header)->assertStatus(422)
            ->assertJsonPath('error.round_dates.0', Message::PLAYOFF_ROUND_DATES_ORDER);
        $this->postJson($url, $body(['2026-12-01', '2026-12-01']), $header)->assertStatus(422);
        $this->postJson($url, $body(['2026-12-01', 'mañana']), $header)->assertStatus(422);
    }

    public function test_only_start_date_computes_by_interval_and_leaves_round_dates_null(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $a = $tournament->groups()->firstOrFail();
        $url = $this->groupsUrl($league, $tournament);
        $id = $this->postJson($url, [
            'type' => 'playoff', 'name' => 'Liguilla', 'start_date' => '2026-12-01', 'round_dates' => ['2026-12-01', '2026-12-03'],
            'sources' => [['source_group_id' => $a->id, 'position_start' => 1, 'position_end' => 4]],
        ], $header)->assertCreated()->json('id');

        $this->patchJson("{$url}/{$id}", ['start_date' => '2026-12-10'], $header)->assertOk()
            ->assertJsonPath('round_dates', null)
            ->assertJsonPath('rounds_detail.1.date', '2026-12-17')
            ->assertJsonPath('final_date', '2026-12-17');
    }

    public function test_changing_sources_so_rounds_change_clears_round_dates(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $a = $tournament->groups()->firstOrFail();
        $url = $this->groupsUrl($league, $tournament);
        $id = $this->postJson($url, [
            'type' => 'playoff', 'name' => 'Liguilla', 'start_date' => '2026-12-01', 'round_dates' => ['2026-12-01', '2026-12-03'],
            'sources' => [['source_group_id' => $a->id, 'position_start' => 1, 'position_end' => 4]],
        ], $header)->assertCreated()->json('id');

        $this->patchJson("{$url}/{$id}", ['sources' => [['source_group_id' => $a->id, 'position_start' => 1, 'position_end' => 4]]], $header)
            ->assertOk()->assertJsonPath('round_dates', ['2026-12-01', '2026-12-03']);

        $this->patchJson("{$url}/{$id}", ['sources' => [['source_group_id' => $a->id, 'position_start' => 1, 'position_end' => 8]]], $header)
            ->assertOk()
            ->assertJsonPath('round_dates', null)
            ->assertJsonPath('start_date', '2026-12-01')
            ->assertJsonPath('final_date', '2026-12-15');
    }

    public function test_finals_aligned_uses_real_round_dates(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $a = $tournament->groups()->firstOrFail();
        $b = $this->makeGroup($tournament, 'Grupo B');
        $url = $this->groupsUrl($league, $tournament);
        $create = fn (string $name, array $dates, $group, int $from, int $end) => $this->postJson($url, [
            'type' => 'playoff', 'name' => $name, 'start_date' => $dates[0], 'round_dates' => $dates,
            'sources' => [['source_group_id' => $group->id, 'position_start' => $from, 'position_end' => $end]],
        ], $header)->assertCreated();

        $create('Oro', ['2026-12-01', '2026-12-20'], $a, 1, 4);
        $create('Plata', ['2026-12-05', '2026-12-20'], $b, 1, 4);
        $this->getJson($url, $header)->assertJsonPath('finals_aligned', true);

        $create('Bronce', ['2026-12-01', '2026-12-21'], $b, 5, 8);
        $this->getJson($url, $header)->assertJsonPath('finals_aligned', false);
    }

    public function test_round_names(): void
    {
        $this->assertSame(['Final'], PlayoffRounds::names(2));
        $this->assertSame(['Semifinal', 'Final'], PlayoffRounds::names(4));
        $this->assertSame(['Cuartos de final', 'Semifinal', 'Final'], PlayoffRounds::names(8));
        $this->assertSame(['Octavos de final', 'Cuartos de final', 'Semifinal', 'Final'], PlayoffRounds::names(16));
        $this->assertSame(['Ronda de 32', 'Octavos de final', 'Cuartos de final', 'Semifinal', 'Final'], PlayoffRounds::names(32));
        $this->assertSame(5, PlayoffRounds::count(32));
    }
}
