<?php

namespace App\Policies;

use App\Core\Enum\Role as RoleEnum;
use App\Models\League;
use App\Models\User;

class LeaguePolicy
{
    /**
     * Superadmin accede a cualquier liga (incluso inactiva, para consultarla y reactivarla); un encargado,
     * solo a las suyas y mientras estén activas.
     */
    public function access(User $user, League $league): bool
    {
        return $user->roles->contains('id', RoleEnum::SUPERADMIN->value)
            || ($league->admin_user_id === $user->id && $league->isActive());
    }
}
