<?php

namespace App\Repositories;

use App\Contracts\Repositories\LeagueRepositoryInterface;
use App\Core\BaseRepository;
use App\Core\Classes\ListQuery;
use App\Models\League;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepository<League>
 */
class LeagueRepository extends BaseRepository implements LeagueRepositoryInterface
{
    public function __construct(League $entity)
    {
        parent::__construct($entity);
    }

    public function paginateManaged(ListQuery $query, ?int $adminUserId, ?string $search): LengthAwarePaginator
    {
        return $this->entity->newQuery()
            ->filter($query->filters)
            ->applySort($query->sort)
            ->when($adminUserId !== null, fn (Builder $builder) => $builder->where('admin_user_id', $adminUserId))
            ->when($search !== null, function (Builder $builder) use ($search) {
                $builder->where(function (Builder $group) use ($search) {
                    $group->where('name', 'LIKE', "%{$search}%")
                        ->orWhereHas('admin', function (Builder $admin) use ($search) {
                            $admin->where(function (Builder $fields) use ($search) {
                                $fields->where('first_name', 'LIKE', "%{$search}%")
                                    ->orWhere('last_name', 'LIKE', "%{$search}%")
                                    ->orWhere('email', 'LIKE', "%{$search}%");
                            });
                        });
                });
            })
            ->with('admin')
            ->paginate($query->perPage)
            ->withQueryString();
    }

    public function countByAdmin(int $adminUserId): int
    {
        return $this->entity->newQuery()->where('admin_user_id', $adminUserId)->count();
    }
}
