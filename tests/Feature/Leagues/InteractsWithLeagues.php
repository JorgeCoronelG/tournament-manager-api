<?php

namespace Tests\Feature\Leagues;

use App\Core\Enum\Role as RoleEnum;
use App\Models\User;
use Tests\Feature\Users\InteractsWithSuperadmin;

trait InteractsWithLeagues
{
    use InteractsWithSuperadmin;

    private function createLeagueAdmin(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->roles()->attach($this->createRole(RoleEnum::LEAGUE_ADMIN));

        return $user;
    }
}
