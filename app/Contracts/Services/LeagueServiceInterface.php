<?php

namespace App\Contracts\Services;

use App\Core\Classes\ListQuery;
use App\Core\Contracts\BaseServiceInterface;
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
    public function paginate(ListQuery $query, ?int $adminUserId, ?string $search): LengthAwarePaginator;
}
