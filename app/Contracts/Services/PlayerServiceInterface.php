<?php

namespace App\Contracts\Services;

use App\Core\Contracts\BaseServiceInterface;
use App\Core\Enum\PlayerGender;
use App\Models\League;
use App\Models\Player;
use App\Models\User;

/**
 * @extends BaseServiceInterface<Player>
 */
interface PlayerServiceInterface extends BaseServiceInterface
{
    /**
     * Corrige la rama del jugador (solo panel). El jugador debe tener o haber tenido roster en la liga (404
     * neutro si no). Se rechaza (422, con los nombres) si tiene un roster activo en un torneo en planeación o
     * en curso que no acepte la nueva rama; los mixtos y los terminados no bloquean. Deja auditoría. Todo en
     * una transacción con los rosters del jugador bloqueados. Cambiar a la misma rama no hace nada.
     */
    public function changeGender(League $league, Player $player, PlayerGender $gender, User $changedBy): Player;
}
