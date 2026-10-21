<?php

namespace Tests\Feature\Tournaments;

use App\Core\Enum\TournamentGender;
use App\Core\Enum\TournamentStatus;
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
            ['id', 'league_id', 'name', 'sport', 'sport_label', 'gender', 'min_age', 'max_age', 'max_roster_size', 'min_matches_for_playoffs', 'start_date', 'status', 'created_at'],
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

    public function test_invalid_gender_or_status_responds_400_and_empty_params_are_ignored(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        Tournament::factory()->count(2)->create(['league_id' => $league->id]);

        $this->getJson("/api/leagues/{$league->id}/tournaments?gender=other", $header)->assertStatus(400);
        $this->getJson("/api/leagues/{$league->id}/tournaments?status=nope", $header)->assertStatus(400);
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
}
