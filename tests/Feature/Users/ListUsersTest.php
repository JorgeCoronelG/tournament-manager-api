<?php

namespace Tests\Feature\Users;

use App\Core\Enum\Message;
use App\Core\Enum\Role as RoleEnum;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListUsersTest extends TestCase
{
    use InteractsWithSuperadmin, RefreshDatabase;

    public function test_excludes_soft_deleted_users(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $role = $this->createRole(RoleEnum::LEAGUE_ADMIN);

        $visible = User::factory()->create();
        $visible->roles()->attach($role);

        $deleted = User::factory()->create();
        $deleted->roles()->attach($role);
        $deleted->delete();

        $response = $this->getJson('/api/users', $this->authHeader($token))->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($deleted->id));
        $this->assertTrue($ids->contains($visible->id));
    }

    public function test_never_includes_the_superadmin(): void
    {
        [$superadmin, $token] = $this->actingAsSuperadmin();

        $response = $this->getJson('/api/users', $this->authHeader($token))->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($superadmin->id));
    }

    public function test_filters_by_role(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $referee = $this->createRole(RoleEnum::REFEREE);
        $leagueAdmin = $this->createRole(RoleEnum::LEAGUE_ADMIN);

        $refereeUser = User::factory()->create();
        $refereeUser->roles()->attach($referee);

        $adminUser = User::factory()->create();
        $adminUser->roles()->attach($leagueAdmin);

        $response = $this->getJson('/api/users?role_id='.RoleEnum::REFEREE->value, $this->authHeader($token))
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($refereeUser->id));
        $this->assertFalse($ids->contains($adminUser->id));
    }

    public function test_fails_with_422_when_role_id_is_not_in_the_assignable_catalog(): void
    {
        [, $token] = $this->actingAsSuperadmin();

        $this->getJson('/api/users?role_id='.RoleEnum::SUPERADMIN->value, $this->authHeader($token))
            ->assertStatus(422)
            ->assertExactJson(['code' => 422, 'error' => Message::INVALID_ROLE]);

        $this->getJson('/api/users?role_id=999', $this->authHeader($token))
            ->assertStatus(422)
            ->assertExactJson(['code' => 422, 'error' => Message::INVALID_ROLE]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function statuses(): array
    {
        return [
            'pending' => ['pending'],
            'inactive' => ['inactive'],
            'active' => ['active'],
        ];
    }

    /**
     * @dataProvider statuses
     */
    public function test_filters_by_status(string $status): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $role = $this->createRole(RoleEnum::LEAGUE_ADMIN);

        $pending = User::factory()->unverified()->create();
        $pending->roles()->attach($role);

        $inactive = User::factory()->create(['is_active' => false]);
        $inactive->roles()->attach($role);

        $active = User::factory()->create(['is_active' => true]);
        $active->roles()->attach($role);

        $expected = ['pending' => $pending, 'inactive' => $inactive, 'active' => $active][$status];
        $others = array_diff([$pending->id, $inactive->id, $active->id], [$expected->id]);

        $response = $this->getJson("/api/users?status={$status}", $this->authHeader($token))->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($expected->id));
        foreach ($others as $otherId) {
            $this->assertFalse($ids->contains($otherId));
        }
    }

    public function test_fails_with_400_when_status_is_invalid(): void
    {
        [, $token] = $this->actingAsSuperadmin();

        $this->getJson('/api/users?status=unknown', $this->authHeader($token))
            ->assertStatus(400)
            ->assertExactJson(['code' => 400, 'error' => Message::INVALID_QUERY_PARAMETER]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function searchableFields(): array
    {
        return [
            'first_name' => ['first_name'],
            'last_name' => ['last_name'],
            'email' => ['email'],
            'phone' => ['phone'],
            'user_code' => ['user_code'],
        ];
    }

    /**
     * @dataProvider searchableFields
     */
    public function test_searches_by_each_field(string $field): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $role = $this->createRole(RoleEnum::LEAGUE_ADMIN);

        $match = User::factory()->create([$field => 'Wenceslao9Unico']);
        $match->roles()->attach($role);

        $noMatch = User::factory()->create();
        $noMatch->roles()->attach($role);

        $response = $this->getJson('/api/users?search=Wenceslao9Unico', $this->authHeader($token))->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($match->id));
        $this->assertFalse($ids->contains($noMatch->id));
    }

    public function test_searches_by_full_name(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $role = $this->createRole(RoleEnum::LEAGUE_ADMIN);

        $match = User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Pérez']);
        $match->roles()->attach($role);

        $noMatch = User::factory()->create(['first_name' => 'Carlos', 'last_name' => 'Gómez']);
        $noMatch->roles()->attach($role);

        $response = $this->getJson('/api/users?search=Ana Pérez', $this->authHeader($token))->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($match->id));
        $this->assertFalse($ids->contains($noMatch->id));
    }

    public function test_combines_search_role_id_and_status(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $referee = $this->createRole(RoleEnum::REFEREE);
        $leagueAdmin = $this->createRole(RoleEnum::LEAGUE_ADMIN);

        $match = User::factory()->create(['first_name' => 'Wences', 'is_active' => true]);
        $match->roles()->attach($referee);

        // Mismo nombre pero otro rol.
        $wrongRole = User::factory()->create(['first_name' => 'Wences', 'is_active' => true]);
        $wrongRole->roles()->attach($leagueAdmin);

        // Mismo rol pero otro nombre.
        $wrongSearch = User::factory()->create(['first_name' => 'Otro', 'is_active' => true]);
        $wrongSearch->roles()->attach($referee);

        $response = $this->getJson(
            '/api/users?search=Wences&role_id='.RoleEnum::REFEREE->value.'&status=active',
            $this->authHeader($token)
        )->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($match->id));
        $this->assertFalse($ids->contains($wrongRole->id));
        $this->assertFalse($ids->contains($wrongSearch->id));
    }

    public function test_orders_by_created_at_descending_by_default(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $role = $this->createRole(RoleEnum::LEAGUE_ADMIN);

        $older = User::factory()->create(['created_at' => now()->subDay()]);
        $older->roles()->attach($role);

        $newer = User::factory()->create(['created_at' => now()]);
        $newer->roles()->attach($role);

        $response = $this->getJson('/api/users', $this->authHeader($token))->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->values();
        $this->assertSame($newer->id, $ids->first());
    }

    public function test_sorts_ascending_and_descending_by_first_name(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $role = $this->createRole(RoleEnum::LEAGUE_ADMIN);

        $ana = User::factory()->create(['first_name' => 'Ana']);
        $ana->roles()->attach($role);

        $beto = User::factory()->create(['first_name' => 'Beto']);
        $beto->roles()->attach($role);

        $ascending = $this->getJson('/api/users?sort=first_name', $this->authHeader($token))->assertOk();
        $this->assertSame([$ana->id, $beto->id], collect($ascending->json('data'))->pluck('id')->all());

        $descending = $this->getJson('/api/users?sort=-first_name', $this->authHeader($token))->assertOk();
        $this->assertSame([$beto->id, $ana->id], collect($descending->json('data'))->pluck('id')->all());
    }

    public function test_fails_with_400_when_sort_field_is_not_allowed(): void
    {
        [, $token] = $this->actingAsSuperadmin();

        $this->getJson('/api/users?sort=password', $this->authHeader($token))
            ->assertStatus(400)
            ->assertExactJson(['code' => 400, 'error' => Message::INVALID_QUERY_PARAMETER]);
    }
}
