<?php

namespace App\Contracts\Services;

use App\Core\Classes\ListQuery;
use App\Core\Contracts\BaseServiceInterface;
use App\Core\Enum\LeagueStatus;
use App\Models\League;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseServiceInterface<League>
 */
interface LeagueServiceInterface extends BaseServiceInterface
{
    /**
     * @return LengthAwarePaginator<int, League>
     */
    public function paginateAccessible(ListQuery $query, User $user, ?string $search): LengthAwarePaginator;

    /**
     * @return LengthAwarePaginator<int, League>
     */
    public function paginate(ListQuery $query, ?int $adminUserId, ?string $search, ?LeagueStatus $status = null): LengthAwarePaginator;

    /**
     * Límites de la liga (null = sin límite) y su uso actual: torneos activos y, de cada torneo en planeación, en
     * curso o en liguilla, sus inscripciones activas.
     *
     * @return array{max_active_tournaments: int|null, max_teams_per_tournament: int|null, usage: array{active_tournaments: int, tournaments: list<array{id: int, name: string, status: string, active_registrations: int}>}}
     */
    public function limits(League $league): array;

    /**
     * Activa o desactiva la liga sin tocar sus datos. Desactivar exige que no tenga torneos en curso ni en liguilla.
     */
    public function updateStatus(int $id, bool $isActive): League;
}
