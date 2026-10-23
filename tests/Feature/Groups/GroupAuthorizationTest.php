<?php

namespace Tests\Feature\Groups;

use App\Core\Enum\Role as RoleEnum;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GroupAuthorizationTest extends TestCase
{
    use InteractsWithGroups, RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function endpoints(): array
    {
        return [
            'index' => ['GET', ''],
            'show' => ['GET', '/{g}'],
            'store' => ['POST', ''],
            'update' => ['PATCH', '/{g}'],
            'destroy' => ['DELETE', '/{g}'],
            'assignments' => ['PUT', '/assignments'],
            'random' => ['POST', '/random-assignment'],
        ];
    }

    /**
     * @return array{0: Tournament, 1: string}
     */
    private function tournamentAndSuffix(string $suffix): array
    {
        $tournament = Tournament::factory()->create();

        return [$tournament, str_replace('{g}', (string) $tournament->groups()->value('id'), $suffix)];
    }

    #[DataProvider('endpoints')]
    public function test_responds_401_without_token(string $method, string $suffix): void
    {
        [$tournament, $suffix] = $this->tournamentAndSuffix($suffix);

        $this->json($method, $this->groupsUrl($tournament->league, $tournament, $suffix))->assertStatus(401);
    }

    #[DataProvider('endpoints')]
    public function test_responds_403_for_roles_without_access(string $method, string $suffix): void
    {
        [$tournament, $suffix] = $this->tournamentAndSuffix($suffix);

        foreach ([RoleEnum::MANAGER, RoleEnum::REFEREE, RoleEnum::PLAYER] as $role) {
            $this->json($method, $this->groupsUrl($tournament->league, $tournament, $suffix), [], $this->headerForRole($role))->assertForbidden();
        }
    }

    #[DataProvider('endpoints')]
    public function test_responds_403_for_the_admin_of_another_league(string $method, string $suffix): void
    {
        [$tournament, $suffix] = $this->tournamentAndSuffix($suffix);
        [, $header] = $this->leagueWithAdminHeader();

        $this->json($method, $this->groupsUrl($tournament->league, $tournament, $suffix), [], $header)->assertForbidden();
    }

    #[DataProvider('endpoints')]
    public function test_responds_404_for_a_tournament_of_another_league(string $method, string $suffix): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        [$foreign, $suffix] = $this->tournamentAndSuffix($suffix);

        $this->json($method, $this->groupsUrl($league, $foreign, $suffix), [], $header)->assertNotFound();
    }
}
