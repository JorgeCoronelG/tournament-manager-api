<?php

namespace App\Contracts\Repositories;

use App\Core\Classes\ListQuery;
use App\Core\Contracts\BaseRepositoryInterface;
use App\Core\Enum\PlayerGender;
use App\Models\Player;
use App\Models\PlayerLeagueBan;
use App\Models\Tournament;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepositoryInterface<Player>
 */
interface PlayerRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Bloquea la fila del jugador y todos sus rosters hasta que termine la transacción.
     */
    public function lockWithRosters(int $playerId): Player;

    /**
     * Si el jugador tiene o tuvo un roster (en cualquier estado) en un torneo de la liga.
     */
    public function hasRosterInLeague(int $playerId, int $leagueId): bool;

    /**
     * Torneos en planeación o en curso donde el jugador tiene un roster activo, por nombre.
     *
     * @return Collection<int, Tournament>
     */
    public function openTournamentsWithActiveRoster(int $playerId): Collection;

    /**
     * Pasa a cancelled las solicitudes pending del jugador en torneos que no aceptan la rama indicada.
     */
    public function cancelPendingRostersNotAccepting(int $playerId, PlayerGender $gender): int;

    /**
     * Jugadores para el listado de una liga con sus equipos activos y si están vetados en ella. Con `$onlyWithRoster`
     * solo los que tienen o tuvieron roster en la liga. `$sort`: name o user_code (con `-` descendente).
     *
     * @return LengthAwarePaginator<int, Player>
     */
    public function paginateForLeague(int $leagueId, bool $onlyWithRoster, ListQuery $query, ?string $search, ?PlayerGender $gender, ?bool $banned): LengthAwarePaginator;

    /**
     * Carga en el jugador los datos del listado para la liga (usuario, equipos activos y `is_banned`).
     */
    public function loadForLeague(Player $player, int $leagueId): Player;

    /**
     * Carga además el historial de plantillas (`leagueRosters`) y los vetos (`leagueBans`) de la liga, del más reciente al más antiguo.
     */
    public function loadDetailForLeague(Player $player, int $leagueId): Player;

    /**
     * Veto vigente (sin levantar) del jugador en la liga, bloqueado hasta que termine la transacción.
     */
    public function lockActiveBan(int $playerId, int $leagueId): ?PlayerLeagueBan;

    public function createBan(int $playerId, int $leagueId, int $bannedByUserId, string $reason): PlayerLeagueBan;

    public function liftBan(PlayerLeagueBan $ban, int $liftedByUserId): PlayerLeagueBan;

    public function updateGender(Player $player, PlayerGender $gender): Player;

    public function recordGenderChange(Player $player, int $changedByUserId, PlayerGender $old, PlayerGender $new): void;
}
