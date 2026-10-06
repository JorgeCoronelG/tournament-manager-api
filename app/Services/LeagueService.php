<?php

namespace App\Services;

use App\Contracts\Repositories\LeagueRepositoryInterface;
use App\Contracts\Services\LeagueServiceInterface;
use App\Core\BaseService;
use App\Core\Classes\ListQuery;
use App\Core\Enum\Role as RoleEnum;
use App\Data\Leagues\LeagueData;
use App\Models\League;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\LaravelData\Data;

/**
 * @extends BaseService<League>
 */
class LeagueService extends BaseService implements LeagueServiceInterface
{
    public function __construct(protected LeagueRepositoryInterface $leagueRepository)
    {
        parent::__construct($leagueRepository);
    }

    /**
     * @param  LeagueData  $data
     */
    public function create(Data $data): League
    {
        /** @var League $league */
        $league = $this->leagueRepository->create([
            'name' => $data->name,
            'admin_user_id' => $data->admin_user_id,
        ]);

        return $league->load('admin');
    }

    /**
     * @param  LeagueData  $data
     */
    public function update(int|string $id, Data $data): League
    {
        /** @var League $league */
        $league = $this->leagueRepository->update($id, [
            'name' => $data->name,
            'admin_user_id' => $data->admin_user_id,
        ]);

        return $league->load('admin');
    }

    public function paginateAccessible(ListQuery $query, User $user, ?string $search): LengthAwarePaginator
    {
        $isSuperadmin = $user->roles->contains('id', RoleEnum::SUPERADMIN->value);

        return $this->leagueRepository->paginateAccessible($query, $isSuperadmin ? null : $user->id, $search);
    }

    public function paginate(ListQuery $query, ?int $adminUserId, ?string $search): LengthAwarePaginator
    {
        return $this->leagueRepository->paginateManaged($query, $adminUserId, $search);
    }
}
