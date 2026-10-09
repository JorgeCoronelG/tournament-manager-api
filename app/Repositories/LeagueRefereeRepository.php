<?php

namespace App\Repositories;

use App\Contracts\Repositories\LeagueRefereeRepositoryInterface;
use App\Core\BaseRepository;
use App\Core\Classes\ListQuery;
use App\Core\Enum\LeagueRefereeStatus;
use App\Core\Enum\Message;
use App\Exceptions\CustomErrorException;
use App\Models\LeagueReferee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\Response;

/**
 * @extends BaseRepository<LeagueReferee>
 */
class LeagueRefereeRepository extends BaseRepository implements LeagueRefereeRepositoryInterface
{
    /** Campos de orden permitidos => columna calificada (el listado une `users`). */
    private const SORTS = [
        'first_name' => 'users.first_name',
        'last_name' => 'users.last_name',
        'created_at' => 'league_referees.created_at',
    ];

    public function __construct(LeagueReferee $entity)
    {
        parent::__construct($entity);
    }

    public function paginateByLeague(int $leagueId, ListQuery $query, ?string $search, ?LeagueRefereeStatus $status): LengthAwarePaginator
    {
        $builder = $this->entity->newQuery()
            ->select('league_referees.*')
            ->join('users', function ($join) {
                $join->on('users.id', '=', 'league_referees.user_id')->whereNull('users.deleted_at');
            })
            ->where('league_referees.league_id', $leagueId)
            ->when($status !== null, fn (Builder $q) => $q->where('league_referees.status', $status))
            ->when($search !== null, function (Builder $q) use ($search) {
                $q->where(function (Builder $group) use ($search) {
                    $group->where('users.first_name', 'LIKE', "%{$search}%")
                        ->orWhere('users.last_name', 'LIKE', "%{$search}%")
                        ->orWhere('users.email', 'LIKE', "%{$search}%")
                        ->orWhere('users.phone', 'LIKE', "%{$search}%")
                        ->orWhere('users.user_code', 'LIKE', "%{$search}%")
                        ->orWhereRaw("CONCAT(users.first_name, ' ', users.last_name) LIKE ?", ["%{$search}%"]);
                });
            });

        $this->applySort($builder, $query->sort ?? 'first_name');

        return $builder->orderBy('league_referees.id')
            ->with('user')
            ->paginate($query->perPage)
            ->withQueryString();
    }

    public function findLinked(int $leagueId, int $userId): LeagueReferee
    {
        return $this->entity->newQuery()
            ->where('league_id', $leagueId)
            ->where('user_id', $userId)
            ->whereHas('user')
            ->with('user')
            ->firstOrFail();
    }

    public function findLinkWithTrashed(int $leagueId, int $userId): ?LeagueReferee
    {
        return $this->entity->newQuery()
            ->withTrashed()
            ->where('league_id', $leagueId)
            ->where('user_id', $userId)
            ->first();
    }

    public function createLink(int $leagueId, int $userId): LeagueReferee
    {
        $link = $this->entity->newInstance(['status' => LeagueRefereeStatus::ACTIVE]);
        $link->forceFill(['league_id' => $leagueId, 'user_id' => $userId]);
        $link->saveOrFail();

        return $link;
    }

    public function updateStatus(LeagueReferee $link, LeagueRefereeStatus $status): LeagueReferee
    {
        $link->fill(['status' => $status]);
        $link->saveOrFail();

        return $link;
    }

    public function restoreLink(LeagueReferee $link, LeagueRefereeStatus $status): LeagueReferee
    {
        $link->restore();

        return $this->updateStatus($link, $status);
    }

    public function countInOtherLeagues(int $userId, int $exceptLeagueId): int
    {
        return $this->entity->newQuery()
            ->where('user_id', $userId)
            ->where('league_id', '!=', $exceptLeagueId)
            ->count();
    }

    /**
     * @param  Builder<LeagueReferee>  $builder
     *
     * @throws CustomErrorException
     */
    private function applySort(Builder $builder, string $sort): void
    {
        foreach (explode(',', $sort) as $field) {
            $direction = str_starts_with($field, '-') ? 'desc' : 'asc';
            $field = ltrim($field, '-');

            if (! isset(self::SORTS[$field])) {
                throw new CustomErrorException(Message::INVALID_QUERY_PARAMETER, Response::HTTP_BAD_REQUEST);
            }

            $builder->orderBy(self::SORTS[$field], $direction);
        }
    }
}
