<?php

namespace Tests\Feature\Tournaments;

use App\Core\Enum\Role;
use App\Models\League;
use App\Models\User;
use Tests\Feature\Leagues\InteractsWithLeagues;

trait InteractsWithTournaments
{
    use InteractsWithLeagues;

    /**
     * @return array{0: League, 1: array<string, string>} Liga de un encargado y su cabecera de autenticación
     */
    private function leagueWithAdminHeader(): array
    {
        $admin = $this->createLeagueAdmin();
        $league = League::factory()->create(['admin_user_id' => $admin->id]);

        return [$league, $this->authHeader($admin->createToken('t')->plainTextToken)];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Copa Apertura',
            'gender' => 'male',
            'min_age' => 18,
            'max_age' => 40,
            'start_date' => '2026-11-01',
        ], $overrides);
    }

    private function otherUserHeader(string $role): array
    {
        $user = User::factory()->create();
        $user->roles()->attach($this->createRole(Role::from((int) $role)));

        return $this->authHeader($user->createToken('t')->plainTextToken);
    }
}
