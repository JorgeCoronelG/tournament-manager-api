<?php

namespace Tests\Feature\Leagues;

use App\Core\Enum\Role as RoleEnum;
use App\Models\League;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SaveLeagueTest extends TestCase
{
    use InteractsWithLeagues, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->token] = $this->actingAsSuperadmin();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function store(array $body): TestResponse
    {
        return $this->postJson('/api/leagues', self::withLimits($body), $this->authHeader($this->token));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function update(int $id, array $body): TestResponse
    {
        return $this->putJson("/api/leagues/{$id}", self::withLimits($body), $this->authHeader($this->token));
    }

    /**
     * El límite de torneos activos es obligatorio (null = sin límite): se completa salvo que la prueba los envíe.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function withLimits(array $body): array
    {
        return $body + ['max_active_tournaments' => null];
    }

    public function test_creates_a_league(): void
    {
        $admin = $this->createLeagueAdmin();

        $this->store(['name' => 'Liga Norte', 'admin_user_id' => $admin->id])
            ->assertCreated()
            ->assertJsonPath('name', 'Liga Norte')
            ->assertJsonPath('admin.id', $admin->id)
            ->assertJsonPath('admin.email', $admin->email);

        $this->assertDatabaseHas('leagues', ['name' => 'Liga Norte', 'admin_user_id' => $admin->id]);
    }

    public function test_a_pending_admin_is_allowed(): void
    {
        $admin = $this->createLeagueAdmin(['email_verified_at' => null]);

        $this->store(['name' => 'Liga Norte', 'admin_user_id' => $admin->id])
            ->assertCreated()
            ->assertJsonPath('admin.status', 'pending');
    }

    public function test_an_admin_can_have_several_leagues(): void
    {
        $admin = $this->createLeagueAdmin();

        $this->store(['name' => 'Liga Uno', 'admin_user_id' => $admin->id])->assertCreated();
        $this->store(['name' => 'Liga Dos', 'admin_user_id' => $admin->id])->assertCreated();

        $this->assertSame(2, $admin->leagues()->count());
    }

    public function test_name_is_required_and_limited_to_150(): void
    {
        $admin = $this->createLeagueAdmin();

        $this->store(['admin_user_id' => $admin->id])->assertStatus(422)->assertJsonStructure(['error' => ['name']]);
        $this->store(['name' => '', 'admin_user_id' => $admin->id])->assertStatus(422)->assertJsonStructure(['error' => ['name']]);
        $this->store(['name' => str_repeat('a', 151), 'admin_user_id' => $admin->id])->assertStatus(422)->assertJsonStructure(['error' => ['name']]);
        $this->store(['name' => str_repeat('a', 150), 'admin_user_id' => $admin->id])->assertCreated();
    }

    public function test_name_must_be_unique_ignoring_case_and_edge_spaces(): void
    {
        $admin = $this->createLeagueAdmin();
        League::factory()->create(['admin_user_id' => $admin->id, 'name' => 'Liga Norte']);

        $this->store(['name' => 'liga NORTE', 'admin_user_id' => $admin->id])
            ->assertStatus(422)->assertJsonStructure(['error' => ['name']]);
        $this->store(['name' => '  Liga Norte  ', 'admin_user_id' => $admin->id])
            ->assertStatus(422)->assertJsonStructure(['error' => ['name']]);

        League::factory()->create(['admin_user_id' => $admin->id, 'name' => 'Liga Sánchez']);
        $this->store(['name' => 'liga  SANCHEZ', 'admin_user_id' => $admin->id])
            ->assertStatus(422)->assertJsonStructure(['error' => ['name']]);
    }

    public function test_same_name_is_allowed_for_a_different_admin(): void
    {
        $first = $this->createLeagueAdmin();
        $second = $this->createLeagueAdmin();
        League::factory()->create(['admin_user_id' => $first->id, 'name' => 'Test 1']);

        $this->store(['name' => 'Test 1', 'admin_user_id' => $second->id])->assertCreated();
        $this->store(['name' => 'Test 1', 'admin_user_id' => $first->id])
            ->assertStatus(422)->assertJsonStructure(['error' => ['name']]);
    }

    public function test_name_of_a_deleted_league_can_be_reused(): void
    {
        $admin = $this->createLeagueAdmin();
        League::factory()->create(['admin_user_id' => $admin->id, 'name' => 'Liga Norte'])->delete();

        $this->store(['name' => 'Liga Norte', 'admin_user_id' => $admin->id])->assertCreated();
    }

    public function test_admin_user_id_is_required(): void
    {
        $this->store(['name' => 'Liga'])->assertStatus(422)->assertJsonStructure(['error' => ['admin_user_id']]);
    }

    public function test_rejects_invalid_admins(): void
    {
        $withoutRole = User::factory()->create();
        $withoutRole->roles()->attach($this->createRole(RoleEnum::REFEREE));
        $inactive = $this->createLeagueAdmin(['is_active' => false]);
        $deleted = $this->createLeagueAdmin();
        $deleted->delete();

        foreach ([9999, $withoutRole->id, $inactive->id, $deleted->id, 'abc'] as $adminId) {
            $this->store(['name' => 'Liga '.$adminId, 'admin_user_id' => $adminId])
                ->assertStatus(422)
                ->assertJsonStructure(['error' => ['admin_user_id']]);
        }

        $this->assertSame(0, League::query()->count());
    }

    public function test_updates_a_league(): void
    {
        $old = $this->createLeagueAdmin();
        $new = $this->createLeagueAdmin();
        $league = League::factory()->create(['admin_user_id' => $old->id, 'name' => 'Liga Vieja']);

        $this->update($league->id, ['name' => 'Liga Nueva', 'admin_user_id' => $new->id])
            ->assertOk()
            ->assertJsonPath('name', 'Liga Nueva')
            ->assertJsonPath('admin.id', $new->id);
    }

    public function test_update_can_keep_its_own_name(): void
    {
        $admin = $this->createLeagueAdmin();
        $league = League::factory()->create(['admin_user_id' => $admin->id, 'name' => 'Liga Norte']);

        $this->update($league->id, ['name' => 'LIGA NORTE', 'admin_user_id' => $admin->id])
            ->assertOk();
    }

    public function test_update_rejects_another_leagues_name_for_the_same_admin(): void
    {
        $admin = $this->createLeagueAdmin();
        League::factory()->create(['admin_user_id' => $admin->id, 'name' => 'Liga Norte']);
        $league = League::factory()->create(['admin_user_id' => $admin->id, 'name' => 'Liga Sur']);

        $this->update($league->id, ['name' => 'Liga Norte', 'admin_user_id' => $admin->id])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['name']]);
    }

    public function test_update_rejects_invalid_admin(): void
    {
        $admin = $this->createLeagueAdmin();
        $league = League::factory()->create(['admin_user_id' => $admin->id, 'name' => 'Liga Sur']);

        $this->update($league->id, ['name' => 'Liga Sur', 'admin_user_id' => 9999])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['admin_user_id']]);
    }

    public function test_update_404s_for_missing_or_deleted_league(): void
    {
        $admin = $this->createLeagueAdmin();
        $body = ['name' => 'X', 'admin_user_id' => $admin->id];

        $this->update(9999, $body)->assertStatus(404);

        $league = League::factory()->create(['admin_user_id' => $admin->id]);
        $league->delete();
        $this->update($league->id, $body)->assertStatus(404);
    }
}
