<?php

namespace App\Contracts\Services;

use App\Core\Classes\ListQuery;
use App\Core\Contracts\BaseServiceInterface;
use App\Core\Enum\PlayerGender;
use App\Models\League;
use App\Models\Player;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseServiceInterface<Player>
 */
interface PlayerServiceInterface extends BaseServiceInterface
{
    /**
     * Jugadores de la liga para el panel. El encargado solo ve a quienes tienen o tuvieron roster en su liga; el
     * superadmin ve a todos. `vetado` siempre se calcula respecto a esta liga.
     *
     * @return LengthAwarePaginator<int, Player>
     */
    public function paginateByLeague(League $league, User $actor, ListQuery $query, ?string $search, ?PlayerGender $gender, ?bool $banned): LengthAwarePaginator;

    /**
     * Datos del listado más el historial de plantillas y los vetos en la liga. 404 neutro si el encargado no puede ver al jugador.
     */
    public function showInLeague(League $league, Player $player, User $actor): Player;

    /**
     * Veta al jugador en la liga (motivo obligatorio): 422 si ya está vetado. En la misma transacción quita sus
     * plantillas activas y cancela sus solicitudes pendientes en los torneos en planeación o en curso de la liga.
     *
     * @return array{player: Player, removed: int, cancelled: int}
     */
    public function ban(League $league, Player $player, User $actor, string $reason): array;

    /**
     * Levanta el veto vigente: 422 si no está vetado. No restaura plantillas ni solicitudes.
     */
    public function unban(League $league, Player $player, User $actor): Player;

    /**
     * Corrige la rama del jugador (solo panel). El jugador debe tener o haber tenido roster en la liga (404
     * neutro si no). Se rechaza (422, con los nombres) si tiene un roster activo en un torneo en planeación o
     * en curso que no acepte la nueva rama; los mixtos y los terminados no bloquean. Las solicitudes pending incompatibles no bloquean: pasan a cancelled. Deja auditoría. Todo en
     * una transacción con los rosters del jugador bloqueados. Cambiar a la misma rama no hace nada.
     */
    public function changeGender(League $league, Player $player, PlayerGender $gender, User $changedBy): Player;
}
