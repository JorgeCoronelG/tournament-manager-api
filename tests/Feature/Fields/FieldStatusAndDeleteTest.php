<?php

namespace Tests\Feature\Fields;

use App\Core\Enum\FieldStatus;
use App\Models\Field;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldStatusAndDeleteTest extends TestCase
{
    use InteractsWithFields, RefreshDatabase;

    public function test_toggles_status_in_both_directions(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $field = Field::factory()->create(['league_id' => $league->id]);
        $url = "/api/leagues/{$league->id}/fields/{$field->id}/status";

        $this->patchJson($url, ['status' => 'inactive'], $header)->assertOk()->assertJsonPath('status', 'inactive');
        $this->assertSame(FieldStatus::INACTIVE, $field->fresh()->status);
        $this->patchJson($url, ['status' => 'active'], $header)->assertOk()->assertJsonPath('status', 'active');
    }

    public function test_invalid_or_missing_status_responds_422(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $field = Field::factory()->create(['league_id' => $league->id]);
        $url = "/api/leagues/{$league->id}/fields/{$field->id}/status";

        $this->patchJson($url, ['status' => 'bogus'], $header)->assertStatus(422)->assertJsonStructure(['error' => ['status']]);
        $this->patchJson($url, [], $header)->assertStatus(422)->assertJsonStructure(['error' => ['status']]);
        $this->assertSame(FieldStatus::ACTIVE, $field->fresh()->status);
    }

    public function test_delete_is_soft_and_the_field_leaves_the_listing(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $field = Field::factory()->create(['league_id' => $league->id]);

        $this->deleteJson("/api/leagues/{$league->id}/fields/{$field->id}", [], $header)->assertNoContent();

        $this->assertSoftDeleted('fields', ['id' => $field->id]);
        $this->assertSame([], $this->getJson("/api/leagues/{$league->id}/fields", $header)->json('data'));
        $this->getJson("/api/leagues/{$league->id}/fields/{$field->id}", $header)->assertStatus(404);
    }
}
