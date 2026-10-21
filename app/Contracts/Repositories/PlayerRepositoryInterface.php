<?php

namespace App\Contracts\Repositories;

use App\Core\Contracts\BaseRepositoryInterface;
use App\Core\Enum\PlayerGender;
use App\Models\Player;
use App\Models\Tournament;
use Illuminate\Database\Eloquent\Collection;

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

    public function updateGender(Player $player, PlayerGender $gender): Player;

    public function recordGenderChange(Player $player, int $changedByUserId, PlayerGender $old, PlayerGender $new): void;
}
