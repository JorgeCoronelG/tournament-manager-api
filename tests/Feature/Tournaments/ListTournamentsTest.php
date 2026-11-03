<?php

namespace Tests\Feature\Tournaments;

use App\Core\Enum\TournamentGender;
use App\Core\Enum\TournamentStatus;
use App\Models\League;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListTournamentsTest extends TestCase
{
    use InteractsWithTournaments, RefreshDatabase;

    public function test_lists_paginated_with_resource_shape(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        Tournament::factory()->count(3)->create(['league_id' => $league->id]);

        $response = $this->getJson("/api/leagues/{$league->id}/tournaments?per_page=2", $header)
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3);

        $this->assertSame(
            ['id', 'league_id', 'name', 'sport', 'sport_label', 'gender', 'min_age', 'max_age', 'max_roster_size', 'min_matches_for_playoffs', 'rounds', 'match_duration_minutes', 'walkover_goals', 'tiebreakers', 'start_date', 'status', 'playoffs_started', 'created_at'],
            array_keys($response->json('data.0'))
        );
    }

    public function test_default_sort_is_start_date_descending(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $old = Tournament::factory()->create(['league_id' => $league->id, 'start_date' => '2025-01-01']);
        $new = Tournament::factory()->create(['league_id' => $league->id, 'start_date' => '2026-05-01']);

        $ids = $this->getJson("/api/leagues/{$league->id}/tournaments", $header)->assertOk()->json('data.*.id');

        $this->assertSame([$new->id, $old->id], $ids);
    }

    public function test_valid_sorts_and_invalid_sort_responds_400(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $b = Tournament::factory()->create(['league_id' => $league->id, 'name' => 'B']);
        $a = Tournament::factory()->create(['league_id' => $league->id, 'name' => 'A']);

        $this->assertSame([$a->id, $b->id], $this->getJson("/api/leagues/{$league->id}/tournaments?sort=name", $header)->json('data.*.id'));
        $this->assertSame([$b->id, $a->id], $this->getJson("/api/leagues/{$league->id}/tournaments?sort=-name", $header)->json('data.*.id'));
        $this->getJson("/api/leagues/{$league->id}/tournaments?sort=start_date", $header)->assertOk();
        $this->getJson("/api/leagues/{$league->id}/tournaments?sort=created_at", $header)->assertOk();
        $this->getJson("/api/leagues/{$league->id}/tournaments?sort=status", $header)->assertStatus(400);
    }

    public function test_search_gender_and_status_combine_with_and(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $match = Tournament::factory()->create([
            'league_id' => $league->id, 'name' => 'Copa Oro',
            'gender' => TournamentGender::FEMALE, 'status' => TournamentStatus::IN_PROGRESS,
        ]);
        Tournament::factory()->create(['league_id' => $league->id, 'name' => 'Copa Plata', 'gender' => TournamentGender::MALE]);
        Tournament::factory()->create(['league_id' => $league->id, 'name' => 'Liga Oro', 'gender' => TournamentGender::FEMALE]);

        $ids = fn (string $qs) => $this->getJson("/api/leagues/{$league->id}/tournaments?{$qs}", $header)
            ->assertOk()->json('data.*.id');

        $this->assertCount(2, $ids('search=Oro'));
        $this->assertCount(2, $ids('gender=female'));
        $this->assertSame([$match->id], $ids('status=in_progress'));
        $this->assertSame([$match->id], $ids('search=Oro&gender=female&status=in_progress'));
        $this->assertSame([], $ids('search=Plata&gender=female'));
    }

    public function test_invalid_gender_responds_400_and_empty_params_are_ignored(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        Tournament::factory()->count(2)->create(['league_id' => $league->id]);

        $this->getJson("/api/leagues/{$league->id}/tournaments?gender=other", $header)->assertStatus(400);
        $this->getJson("/api/leagues/{$league->id}/tournaments?search=&gender=&status=&sort=", $header)
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_excludes_deleted_and_other_leagues(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $visible = Tournament::factory()->create(['league_id' => $league->id]);
        Tournament::factory()->create(['league_id' => $league->id])->delete();
        Tournament::factory()->create();

        $this->getJson("/api/leagues/{$league->id}/tournaments", $header)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->id);
    }

    /**
     * @return list<int> ids de los torneos de la liga con cada estado: planning, in_progress, playoffs, finished
     */
    private function oneOfEachStatus(League $league): array
    {
        return array_map(
            fn (TournamentStatus $status) => Tournament::factory()->status($status)->create(['league_id' => $league->id])->id,
            TournamentStatus::cases()
        );
    }

    public function test_filters_by_one_or_several_statuses_and_excluded_statuses(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        [$planning, $inProgress, $playoffs, $finished] = $this->oneOfEachStatus($league);
        $ids = fn (string $qs) => collect($this->getJson("/api/leagues/{$league->id}/tournaments?{$qs}", $header)->assertOk()->json('data.*.id'))->sort()->values()->all();

        $this->assertSame([$planning, $inProgress, $playoffs, $finished], $ids(''));
        $this->assertSame([$inProgress], $ids('status=in_progress'));
        $this->assertSame([$inProgress], $ids('status[]=in_progress'));
        $this->assertSame([$planning, $inProgress, $playoffs], $ids('status[]=planning&status[]=in_progress&status[]=playoffs'));
        $this->assertSame([$planning, $inProgress, $playoffs], $ids('exclude_status=finished'));
        $this->assertSame([$planning, $playoffs], $ids('exclude_status[]=finished&exclude_status[]=in_progress'));
        $this->assertSame([$playoffs], $ids('status[]=in_progress&status[]=playoffs&exclude_status=in_progress'));
        $this->assertSame([], $ids('status=finished&exclude_status=finished'));
        $this->assertSame([$planning, $inProgress, $playoffs, $finished], $ids('status[]=&exclude_status='));
    }

    public function test_an_invalid_status_responds_422(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $this->oneOfEachStatus($league);

        foreach (['status=nope', 'status[]=planning&status[]=nope', 'exclude_status=nope', 'status[][]=planning'] as $qs) {
            $this->getJson("/api/leagues/{$league->id}/tournaments?{$qs}", $header)->assertStatus(422)
                ->assertExactJson(['code' => 422, 'error' => 'Estado de torneo inválido.']);
        }
    }

    public function test_the_total_reflects_the_status_filter_with_pagination(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        Tournament::factory()->count(3)->status(TournamentStatus::IN_PROGRESS)->create(['league_id' => $league->id]);
        Tournament::factory()->count(2)->status(TournamentStatus::PLAYOFFS)->create(['league_id' => $league->id]);
        Tournament::factory()->count(4)->status(TournamentStatus::FINISHED)->create(['league_id' => $league->id]);

        $this->getJson("/api/leagues/{$league->id}/tournaments?status[]=in_progress&status[]=playoffs&per_page=2&page=2", $header)->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 5)->assertJsonPath('meta.currentPage', 2);
        $this->getJson("/api/leagues/{$league->id}/tournaments?exclude_status=finished&per_page=2", $header)->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 5);
        $this->getJson("/api/leagues/{$league->id}/tournaments?per_page=2", $header)->assertOk()->assertJsonPath('meta.total', 9);
    }
}
