<?php

namespace Tests\Feature\Users;

use App\Core\Enum\Role as RoleEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolesListTest extends TestCase
{
    use InteractsWithSuperadmin, RefreshDatabase;

    public function test_lists_assignable_roles_excluding_superadmin(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $this->createRole(RoleEnum::LEAGUE_ADMIN);
        $this->createRole(RoleEnum::REFEREE);
        $this->createRole(RoleEnum::MANAGER);
        $this->createRole(RoleEnum::PLAYER);

        $response = $this->getJson('/api/roles', $this->authHeader($token))->assertOk();

        $codes = collect($response->json())->pluck('code');
        $this->assertFalse($codes->contains('superadmin'));
        $this->assertTrue($codes->contains('league_admin'));
        $this->assertTrue($codes->contains('player'));
    }
}
