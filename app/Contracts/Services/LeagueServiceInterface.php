<?php

namespace App\Contracts\Services;

use App\Core\Classes\ListQuery;
use App\Core\Contracts\BaseServiceInterface;
use App\Models\League;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseServiceInterface<League>
 */
interface LeagueServiceInterface extends BaseServiceInterface
{
    /**
     * @return LengthAwarePaginator<int, League>
     */
    public function paginate(ListQuery $query, ?int $adminUserId, ?string $search): LengthAwarePaginator;
}
