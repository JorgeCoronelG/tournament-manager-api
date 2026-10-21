<?php

namespace Tests\Feature\Registrations;

use App\Core\Enum\Role as RoleEnum;
use App\Models\Registration;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegistrationAuthorizationTest extends TestCase
{
    use InteractsWithRegistrations, RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function endpoints(): array
    {
        return [
            'index' => ['GET', ''],
            'store' => ['POST', ''],
            'available teams' => ['GET', '/available-teams'],
            'destroy' => ['DELETE', '/{r}'],
        ];
    }

    /**
     * @return array{0: Tournament, 1: string} Torneo y sufijo con la inscripción (real) sustituida
     */
    private function tournamentAndSuffix(string $suffix): array
    {
        $tournament = Tournament::factory()->create();
        $registration = Registration::factory()->create(['tournament_id' => $tournament->id]);

        return [$tournament, str_replace('{r}', (string) $registration->id, $suffix)];
    }

    #[DataProvider('endpoints')]
    public function test_responds_401_without_token(string $method, string $suffix): void
    {
        [$tournament, $suffix] = $this->tournamentAndSuffix($suffix);

        $this->json($method, $this->registrationsUrl($tournament->league, $tournament, $suffix))->assertStatus(401);
    }

    #[DataProvider('endpoints')]
    public function test_responds_403_for_roles_without_access(string $method, string $suffix): void
    {
        [$tournament, $suffix] = $this->tournamentAndSuffix($suffix);
        $url = $this->registrationsUrl($tournament->league, $tournament, $suffix);

        foreach ([RoleEnum::MANAGER, RoleEnum::REFEREE, RoleEnum::PLAYER] as $role) {
            $this->json($method, $url, [], $this->headerForRole($role))->assertForbidden();
        }
    }

    #[DataProvider('endpoints')]
    public function test_responds_403_for_the_admin_of_another_league(string $method, string $suffix): void
    {
        [$tournament, $suffix] = $this->tournamentAndSuffix($suffix);
        [, $header] = $this->leagueWithAdminHeader();

        $this->json($method, $this->registrationsUrl($tournament->league, $tournament, $suffix), [], $header)->assertForbidden();
    }
}
