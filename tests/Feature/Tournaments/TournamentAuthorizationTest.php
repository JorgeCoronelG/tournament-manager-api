<?php

namespace Tests\Feature\Tournaments;

use App\Core\Enum\Role as RoleEnum;
use App\Models\League;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TournamentAuthorizationTest extends TestCase
{
    use InteractsWithTournaments, RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function endpoints(): array
    {
        return [
            'index' => ['GET', ''],
            'store' => ['POST', ''],
            'show' => ['GET', '/{t}'],
            'update' => ['PUT', '/{t}'],
            'status' => ['PATCH', '/{t}/status'],
            'destroy' => ['DELETE', '/{t}'],
        ];
    }

    /**
     * @dataProvider endpoints
     */
    public function test_responds_401_without_token(string $method, string $suffix): void
    {
        $tournament = Tournament::factory()->create();
        $url = "/api/leagues/{$tournament->league_id}/tournaments".str_replace('{t}', (string) $tournament->id, $suffix);

        $this->json($method, $url)->assertStatus(401);
    }

    /**
     * @dataProvider endpoints
     */
    public function test_responds_403_for_other_roles(string $method, string $suffix): void
    {
        $tournament = Tournament::factory()->create();
        $url = "/api/leagues/{$tournament->league_id}/tournaments".str_replace('{t}', (string) $tournament->id, $suffix);

        foreach ([RoleEnum::REFEREE, RoleEnum::MANAGER, RoleEnum::PLAYER] as $role) {
            $this->json($method, $url, [], $this->otherUserHeader((string) $role->value))->assertStatus(403);
        }
    }

    /**
     * @dataProvider endpoints
     */
    public function test_league_admin_cannot_operate_on_a_foreign_league(string $method, string $suffix): void
    {
        [, $header] = $this->leagueWithAdminHeader();
        $tournament = Tournament::factory()->create();
        $url = "/api/leagues/{$tournament->league_id}/tournaments".str_replace('{t}', (string) $tournament->id, $suffix);

        $this->json($method, $url, [], $header)->assertStatus(403);
    }

    /**
     * @dataProvider endpoints
     */
    public function test_responds_404_for_a_missing_league(string $method, string $suffix): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $tournament = Tournament::factory()->create();
        $url = '/api/leagues/99999/tournaments'.str_replace('{t}', (string) $tournament->id, $suffix);

        $this->json($method, $url, [], $this->authHeader($token))->assertStatus(404);
    }

    /**
     * @dataProvider endpoints
     */
    public function test_responds_404_for_a_tournament_of_another_league(string $method, string $suffix): void
    {
        if (! str_contains($suffix, '{t}')) {
            $this->markTestSkipped('Sin parámetro de torneo.');
        }

        [$league, $header] = $this->leagueWithAdminHeader();
        $foreign = Tournament::factory()->create();
        $url = "/api/leagues/{$league->id}/tournaments".str_replace('{t}', (string) $foreign->id, $suffix);

        $this->json($method, $url, $this->payload(['status' => 'in_progress']), $header)->assertStatus(404);
        $this->assertNull(Tournament::query()->find($foreign->id)?->deleted_at);
    }

    public function test_superadmin_operates_on_any_league(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $league = League::factory()->create();
        $header = $this->authHeader($token);

        $id = $this->postJson("/api/leagues/{$league->id}/tournaments", $this->payload(), $header)
            ->assertCreated()->json('id');

        $this->getJson("/api/leagues/{$league->id}/tournaments/{$id}", $header)->assertOk();
        $this->getJson("/api/leagues/{$league->id}/tournaments", $header)->assertOk();
    }

    public function test_league_admin_operates_on_their_own_league(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();

        $this->postJson("/api/leagues/{$league->id}/tournaments", $this->payload(), $header)->assertCreated();
    }
}
