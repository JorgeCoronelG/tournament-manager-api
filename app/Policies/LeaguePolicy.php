<?php

namespace App\Policies;

use App\Core\Enum\Role as RoleEnum;
use App\Models\League;
use App\Models\User;

class LeaguePolicy
{
    /**
     * Superadmin accede a cualquier liga; un encargado, solo a las suyas.
     */
    public function access(User $user, League $league): bool
    {
        return $user->roles->contains('id', RoleEnum::SUPERADMIN->value)
            || $league->admin_user_id === $user->id;
    }
}
