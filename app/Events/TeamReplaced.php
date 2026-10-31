<?php

namespace App\Events;

use App\Models\Registration;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Una inscripción cambió de equipo. Sin listeners todavía: queda listo para notificar.
 */
class TeamReplaced
{
    use Dispatchable;

    public function __construct(public Registration $registration, public int $oldTeamId, public int $newTeamId, public int $changedByUserId) {}
}
