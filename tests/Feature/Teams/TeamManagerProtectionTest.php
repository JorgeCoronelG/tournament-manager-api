<?php

namespace Tests\Feature\Teams;

use App\Core\Enum\Role as RoleEnum;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamManagerProtectionTest extends TestCase
{
    use InteractsWithTeams, RefreshDatabase;

    public function test_cannot_delete_a_user_who_manages_a_team(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $manager = $this->createManagerUser();
        Team::factory()->count(2)->create(['manager_user_id' => $manager->id]);

        $this->deleteJson("/api/users/{$manager->id}", [], $this->authHeader($token))
            ->assertStatus(422)
            ->assertJsonPath('error', 'No se puede eliminar al usuario porque es delegado de 2 equipos. Reasigne primero el delegado.');

        $this->assertNotSoftDeleted('users', ['id' => $manager->id]);
    }

    public function test_cannot_remove_the_manager_role_from_a_team_manager(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $this->createRole(RoleEnum::PLAYER);
        $manager = $this->createManagerUser();
        Team::factory()->create(['manager_user_id' => $manager->id]);

        $this->putJson("/api/users/{$manager->id}", [
            'first_name' => 'Nuevo',
            'last_name' => 'Nombre',
            'email' => $manager->email,
            'phone' => '5512345678',
            'roles' => [RoleEnum::PLAYER->value],
            'is_active' => true,
        ], $this->authHeader($token))->assertStatus(422);

        $this->assertSame([RoleEnum::MANAGER->value], $manager->roles()->pluck('roles.id')->all());
    }

    public function test_can_deactivate_a_team_manager(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $manager = $this->createManagerUser();
        Team::factory()->create(['manager_user_id' => $manager->id]);

        $this->patchJson("/api/users/{$manager->id}/status", ['is_active' => false], $this->authHeader($token))->assertOk();

        $this->assertFalse($manager->fresh()->is_active);
    }

    public function test_can_delete_a_manager_whose_teams_are_deleted_or_who_has_none(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $withDeletedTeam = $this->createManagerUser();
        Team::factory()->create(['manager_user_id' => $withDeletedTeam->id])->delete();
        $free = $this->createManagerUser();

        $this->deleteJson("/api/users/{$withDeletedTeam->id}", [], $this->authHeader($token))->assertNoContent();
        $this->deleteJson("/api/users/{$free->id}", [], $this->authHeader($token))->assertNoContent();
    }
}
