<?php

namespace App\Contracts\Repositories;

use App\Core\Classes\ListQuery;
use App\Core\Contracts\BaseRepositoryInterface;
use App\Models\League;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepositoryInterface<League>
 */
interface LeagueRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Excluye eliminadas y carga el encargado de forma eager.
     *
     * @return LengthAwarePaginator<int, League>
     */
    public function paginateManaged(ListQuery $query, ?int $adminUserId, ?string $search): LengthAwarePaginator;

    /**
     * Ligas no eliminadas que administra el usuario.
     */
    public function countByAdmin(int $adminUserId): int;
}
