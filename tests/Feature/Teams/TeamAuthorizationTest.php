<?php

namespace Tests\Feature\Teams;

use App\Core\Enum\Role as RoleEnum;
use App\Models\League;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TeamAuthorizationTest extends TestCase
{
    use InteractsWithTeams, RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function endpoints(): array
    {
        return [
            'index' => ['GET', ''],
            'store' => ['POST', ''],
            'managers' => ['GET', '/managers'],
            'lookup' => ['GET', '/manager-lookup?user_code=ABC'],
            'show' => ['GET', '/{t}'],
            'update' => ['PUT', '/{t}'],
            'status' => ['PATCH', '/{t}/status'],
            'destroy' => ['DELETE', '/{t}'],
            'upload logo' => ['POST', '/{t}/logo'],
            'destroy logo' => ['DELETE', '/{t}/logo'],
            'assign manager' => ['PUT', '/{t}/manager'],
            'update manager' => ['PATCH', '/{t}/manager'],
            'destroy manager' => ['DELETE', '/{t}/manager'],
            'resend invitation' => ['POST', '/{t}/manager/resend-invitation'],
        ];
    }

    private function url(Team $team, string $suffix, ?int $leagueId = null): string
    {
        return '/api/leagues/'.($leagueId ?? $team->league_id).'/teams'.str_replace('{t}', (string) $team->id, $suffix);
    }

    #[DataProvider('endpoints')]
    public function test_responds_401_without_token(string $method, string $suffix): void
    {
        $this->json($method, $this->url(Team::factory()->create(), $suffix))->assertStatus(401);
    }

    #[DataProvider('endpoints')]
    public function test_responds_403_for_referee_and_player(string $method, string $suffix): void
    {
        $url = $this->url(Team::factory()->create(), $suffix);

        foreach ([RoleEnum::REFEREE, RoleEnum::PLAYER] as $role) {
            $this->json($method, $url, [], $this->headerForRole($role))->assertForbidden();
        }
    }

    #[DataProvider('endpoints')]
    public function test_responds_403_for_the_admin_of_another_league(string $method, string $suffix): void
    {
        $team = Team::factory()->create();
        [, $header] = $this->leagueWithAdminHeader();

        $this->json($method, $this->url($team, $suffix), [], $header)->assertForbidden();
    }

    #[DataProvider('endpoints')]
    public function test_a_team_of_another_league_in_the_route_is_404(string $method, string $suffix): void
    {
        if (! str_contains($suffix, '{t}')) {
            $this->markTestSkipped('No lleva equipo en la ruta.');
        }

        [$league, $header] = $this->leagueWithAdminHeader();
        $foreign = Team::factory()->create(['league_id' => League::factory()->create()->id]);

        $this->json($method, $this->url($foreign, $suffix, $league->id), [], $header)->assertNotFound();
    }

    public function test_superadmin_can_reach_any_league(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $team = Team::factory()->create();

        $this->getJson($this->url($team, '/{t}'), $this->authHeader($token))
            ->assertOk()
            ->assertJsonPath('id', $team->id);
    }
}
