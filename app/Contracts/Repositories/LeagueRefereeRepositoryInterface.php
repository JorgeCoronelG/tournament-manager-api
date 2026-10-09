<?php

namespace App\Contracts\Repositories;

use App\Core\Classes\ListQuery;
use App\Core\Contracts\BaseRepositoryInterface;
use App\Core\Enum\LeagueRefereeStatus;
use App\Models\LeagueReferee;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepositoryInterface<LeagueReferee>
 */
interface LeagueRefereeRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Vínculos no eliminados de una liga cuyo usuario tampoco está eliminado, con el usuario cargado.
     * `search` filtra por nombre, apellido, correo, teléfono y user_code del usuario.
     *
     * @return LengthAwarePaginator<int, LeagueReferee>
     */
    public function paginateByLeague(int $leagueId, ListQuery $query, ?string $search, ?LeagueRefereeStatus $status): LengthAwarePaginator;

    /**
     * Vínculo no eliminado entre la liga y el usuario (ModelNotFoundException si no existe), con el usuario cargado.
     */
    public function findLinked(int $leagueId, int $userId): LeagueReferee;

    /**
     * Incluye vínculos eliminados.
     */
    public function findLinkWithTrashed(int $leagueId, int $userId): ?LeagueReferee;

    public function createLink(int $leagueId, int $userId): LeagueReferee;

    public function updateStatus(LeagueReferee $link, LeagueRefereeStatus $status): LeagueReferee;

    /**
     * Restaura un vínculo eliminado y lo deja con el status indicado.
     */
    public function restoreLink(LeagueReferee $link, LeagueRefereeStatus $status): LeagueReferee;

    /**
     * Vínculos no eliminados del usuario en ligas distintas a la indicada.
     */
    public function countInOtherLeagues(int $userId, int $exceptLeagueId): int;
}
