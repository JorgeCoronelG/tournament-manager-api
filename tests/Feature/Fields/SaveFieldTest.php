<?php

namespace Tests\Feature\Fields;

use App\Core\Enum\FieldStatus;
use App\Core\Enum\Message;
use App\Models\Field;
use App\Models\League;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaveFieldTest extends TestCase
{
    use InteractsWithFields, RefreshDatabase;

    public function test_creates_an_inactive_field_when_status_is_sent(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();

        $this->postJson("/api/leagues/{$league->id}/fields", ['name' => 'Cancha Sur', 'status' => 'inactive'], $header)
            ->assertCreated()
            ->assertJsonPath('status', 'inactive');
    }

    public function test_rejects_an_invalid_status_on_create(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();

        $this->postJson("/api/leagues/{$league->id}/fields", ['name' => 'Cancha Sur', 'status' => 'otro'], $header)
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['status']]);
    }

    public function test_creates_an_active_field_in_the_route_league_trimming_the_name(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $other = League::factory()->create();

        $response = $this->postJson(
            "/api/leagues/{$league->id}/fields",
            ['name' => '  Cancha Norte  ', 'league_id' => $other->id],
            $header
        )->assertCreated()
            ->assertJsonPath('league_id', $league->id)
            ->assertJsonPath('name', 'Cancha Norte')
            ->assertJsonPath('status', 'active');

        $this->assertSame(
            ['id', 'league_id', 'name', 'status', 'created_at', 'updated_at'],
            array_keys($response->json())
        );
    }

    public function test_shows_a_field(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $field = Field::factory()->create(['league_id' => $league->id]);

        $this->getJson("/api/leagues/{$league->id}/fields/{$field->id}", $header)
            ->assertOk()->assertJsonPath('id', $field->id);
    }

    public function test_update_changes_only_the_name(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $field = Field::factory()->status(FieldStatus::INACTIVE)->create(['league_id' => $league->id]);

        $this->putJson("/api/leagues/{$league->id}/fields/{$field->id}", ['name' => 'Nueva', 'status' => 'active'], $header)
            ->assertOk()->assertJsonPath('name', 'Nueva')->assertJsonPath('status', 'inactive');
    }

    public function test_update_can_keep_its_own_name(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $field = Field::factory()->create(['league_id' => $league->id, 'name' => 'Cancha 1']);

        $this->putJson("/api/leagues/{$league->id}/fields/{$field->id}", ['name' => ' cancha 1 '], $header)->assertOk();
    }

    public function test_name_is_required_and_limited_to_150(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $url = "/api/leagues/{$league->id}/fields";

        $this->postJson($url, ['name' => ''], $header)->assertStatus(422)->assertJsonStructure(['error' => ['name']]);
        $this->postJson($url, ['name' => '   '], $header)->assertStatus(422)->assertJsonStructure(['error' => ['name']]);
        $this->postJson($url, [], $header)->assertStatus(422)->assertJsonStructure(['error' => ['name']]);
        $this->postJson($url, ['name' => str_repeat('a', 151)], $header)->assertStatus(422)->assertJsonStructure(['error' => ['name']]);
        $this->postJson($url, ['name' => str_repeat('a', 150)], $header)->assertCreated();
    }

    public function test_duplicate_name_in_the_same_league_is_rejected_ignoring_case_and_spaces(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $existing = Field::factory()->create(['league_id' => $league->id, 'name' => 'Cancha Norte']);
        Field::factory()->create(['league_id' => $league->id, 'name' => 'Cancha Sur']);

        $this->postJson("/api/leagues/{$league->id}/fields", ['name' => '  CANCHA norte '], $header)
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['name']])
            ->assertJsonPath('error.name.0', Message::FIELD_NAME_TAKEN);

        $this->putJson("/api/leagues/{$league->id}/fields/{$existing->id}", ['name' => 'cancha sur'], $header)
            ->assertStatus(422)->assertJsonStructure(['error' => ['name']]);
    }

    public function test_same_name_is_allowed_in_another_league(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        Field::factory()->create(['name' => 'Cancha Norte']);

        $this->postJson("/api/leagues/{$league->id}/fields", ['name' => 'Cancha Norte'], $header)->assertCreated();
    }

    public function test_same_name_as_a_deleted_field_is_allowed(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        Field::factory()->create(['league_id' => $league->id, 'name' => 'Cancha Norte'])->delete();

        $this->postJson("/api/leagues/{$league->id}/fields", ['name' => 'Cancha Norte'], $header)->assertCreated();
    }
}
