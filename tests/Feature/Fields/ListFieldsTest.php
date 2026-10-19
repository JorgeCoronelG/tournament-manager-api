<?php

namespace Tests\Feature\Fields;

use App\Core\Enum\FieldStatus;
use App\Models\Field;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListFieldsTest extends TestCase
{
    use InteractsWithFields, RefreshDatabase;

    public function test_lists_paginated_with_envelope_and_only_the_league_fields(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        Field::factory()->count(3)->create(['league_id' => $league->id]);
        Field::factory()->create();

        $this->getJson("/api/leagues/{$league->id}/fields?per_page=2", $header)
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3);
    }

    public function test_default_sort_is_name_ascending_and_sort_param_is_honored(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $b = Field::factory()->create(['league_id' => $league->id, 'name' => 'B']);
        $a = Field::factory()->create(['league_id' => $league->id, 'name' => 'A']);
        $url = "/api/leagues/{$league->id}/fields";

        $this->assertSame([$a->id, $b->id], $this->getJson($url, $header)->json('data.*.id'));
        $this->assertSame([$a->id, $b->id], $this->getJson("{$url}?sort=name", $header)->json('data.*.id'));
        $this->assertSame([$b->id, $a->id], $this->getJson("{$url}?sort=-name", $header)->json('data.*.id'));
        $this->getJson("{$url}?sort=created_at", $header)->assertOk();
        $this->getJson("{$url}?sort=status", $header)->assertStatus(400);
    }

    public function test_search_and_status_filters_combine(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $match = Field::factory()->status(FieldStatus::INACTIVE)->create(['league_id' => $league->id, 'name' => 'Cancha Oro']);
        Field::factory()->create(['league_id' => $league->id, 'name' => 'Cancha Oro 2']);
        Field::factory()->create(['league_id' => $league->id, 'name' => 'Cancha Plata']);

        $ids = fn (string $qs) => $this->getJson("/api/leagues/{$league->id}/fields?{$qs}", $header)
            ->assertOk()->json('data.*.id');

        $this->assertCount(2, $ids('search=Oro'));
        $this->assertCount(1, $ids('status=inactive'));
        $this->assertSame([$match->id], $ids('search=Oro&status=inactive'));
        $this->assertCount(3, $ids('search=&status='));
    }

    public function test_invalid_status_filter_is_rejected(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();

        $this->getJson("/api/leagues/{$league->id}/fields?status=bogus", $header)->assertStatus(400);
    }
}
