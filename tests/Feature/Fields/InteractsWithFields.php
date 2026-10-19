<?php

namespace Tests\Feature\Fields;

use App\Core\Enum\Role;
use App\Models\League;
use App\Models\User;
use Tests\Feature\Leagues\InteractsWithLeagues;

trait InteractsWithFields
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

    private function otherUserHeader(Role $role): array
    {
        $user = User::factory()->create();
        $user->roles()->attach($this->createRole($role));

        return $this->authHeader($user->createToken('t')->plainTextToken);
    }
}
