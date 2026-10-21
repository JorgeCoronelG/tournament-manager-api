<?php

namespace App\Contracts\Repositories;

use App\Core\Classes\ListQuery;
use App\Core\Contracts\BaseRepositoryInterface;
use App\Core\Enum\Sport;
use App\Core\Enum\TeamStatus;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepositoryInterface<Team>
 */
interface TeamRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Equipos no eliminados de una liga, con su delegado. `search` busca en el nombre del equipo
     * y en el nombre, correo o clave del delegado; `hasManager` filtra por si tiene delegado.
     *
     * @return LengthAwarePaginator<int, Team>
     */
    public function paginateByLeague(
        int $leagueId,
        ListQuery $query,
        ?string $search,
        ?TeamStatus $status,
        ?bool $hasManager,
        ?int $managerUserId = null
    ): LengthAwarePaginator;

    /**
     * Delegados con al menos un equipo no eliminado en la liga, por nombre y apellido: son las
     * opciones del filtro por delegado.
     *
     * @return Collection<int, User>
     */
    public function managersByLeague(int $leagueId): Collection;

    /**
     * Crea el equipo en la liga indicada, activo y con el delegado indicado (si lo hay).
     *
     * @param  array<string, mixed>  $data
     */
    public function createInLeague(int $leagueId, array $data, ?int $managerUserId): Team;

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateModel(Team $team, array $data): Team;

    /**
     * Si la liga ya tiene un equipo no eliminado de ese deporte con ese nombre y ese delegado (`null`: sin delegado),
     * sin distinguir mayúsculas ni espacios repetidos o en los extremos. Bloquea las filas leídas para
     * que la comprobación y la escritura de una misma transacción no se crucen con otra petición.
     */
    public function existsWithNameAndManager(int $leagueId, Sport $sport, string $name, ?int $managerUserId, ?int $exceptTeamId = null): bool;

    public function updateStatus(Team $team, TeamStatus $status): Team;

    public function setManager(Team $team, ?int $managerUserId): Team;

    public function setLogoPath(Team $team, ?string $logoPath): Team;

    /**
     * Equipos no eliminados que dirige el usuario en ligas distintas a la indicada.
     */
    public function countManagedInOtherLeagues(int $userId, int $exceptLeagueId): int;

    /**
     * Equipos no eliminados que dirige el usuario, en cualquier liga.
     */
    public function countByManager(int $userId): int;
}
