<?php

namespace App\Events;

use App\Models\Registration;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un equipo fue dado de baja de un torneo en curso o en liguilla. Sin listeners todavía: queda listo para notificar.
 */
class TeamWithdrawn
{
    use Dispatchable;

    public function __construct(public Registration $registration, public int $changedByUserId) {}
}
