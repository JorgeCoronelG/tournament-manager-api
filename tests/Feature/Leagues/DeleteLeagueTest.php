<?php

namespace Tests\Feature\Leagues;

use App\Core\Enum\Message;
use App\Models\League;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeleteLeagueTest extends TestCase
{
    use InteractsWithLeagues, RefreshDatabase;

    public function test_soft_deletes_a_league_and_then_404s(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $league = League::factory()->create(['admin_user_id' => $this->createLeagueAdmin()->id]);

        $this->deleteJson("/api/leagues/{$league->id}", [], $this->authHeader($token))->assertNoContent();

        $this->assertSoftDeleted($league);

        $this->getJson("/api/leagues/{$league->id}", $this->authHeader($token))
            ->assertStatus(404)
            ->assertExactJson(['code' => 404, 'error' => Message::MODEL_NOT_FOUND_EXCEPTION]);
        $this->deleteJson("/api/leagues/{$league->id}", [], $this->authHeader($token))->assertStatus(404);
    }
}
