<?php

namespace Tests\Feature\Teams;

use App\Core\Enum\Role as RoleEnum;
use App\Models\League;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Feature\Leagues\InteractsWithLeagues;

trait InteractsWithTeams
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
     * Usuario con rol manager. Por defecto con cuenta activada.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createManagerUser(array $attributes = [], bool $pending = false): User
    {
        $user = User::factory()->create(array_merge([
            'user_code' => Str::upper(Str::random(8)),
            'email_verified_at' => $pending ? null : now(),
        ], $attributes));
        $user->roles()->attach($this->createRole(RoleEnum::MANAGER));

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function managerPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Luis',
            'last_name' => 'Hernández',
            'email' => 'luis@mail.test',
            'phone' => '5598765432',
        ], $overrides);
    }

    /**
     * @return array<string, string>
     */
    private function headerForRole(RoleEnum $role): array
    {
        $user = User::factory()->create();
        $user->roles()->attach($this->createRole($role));

        return $this->authHeader($user->createToken('t')->plainTextToken);
    }
}
