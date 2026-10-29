<?php

namespace App\Services;

use App\Contracts\Repositories\FieldRepositoryInterface;
use App\Contracts\Repositories\LeagueRepositoryInterface;
use App\Contracts\Repositories\TournamentRepositoryInterface;
use App\Contracts\Services\LeagueServiceInterface;
use App\Core\BaseService;
use App\Core\Classes\ListQuery;
use App\Core\Enum\Message;
use App\Core\Enum\Role as RoleEnum;
use App\Core\Enum\TournamentStatus;
use App\Data\Leagues\LeagueData;
use App\Exceptions\CustomErrorException;
use App\Models\League;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\Data;
use Symfony\Component\HttpFoundation\Response;

/**
 * @extends BaseService<League>
 */
class LeagueService extends BaseService implements LeagueServiceInterface
{
    public function __construct(
        protected LeagueRepositoryInterface $leagueRepository,
        protected TournamentRepositoryInterface $tournamentRepository,
        protected FieldRepositoryInterface $fieldRepository,
    ) {
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

    /**
     * Elimina la liga y sus canchas en una transacción; solo se puede si todos sus torneos están finalizados.
     *
     * @throws CustomErrorException
     */
    public function delete(int|string $id): void
    {
        $league = $this->leagueRepository->findById($id);

        if ($this->tournamentRepository->countByLeagueExceptStatus($league->id, TournamentStatus::FINISHED) > 0) {
            throw new CustomErrorException(Message::getMessageLeagueHasActiveTournaments($league->name), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        DB::transaction(function () use ($league): void {
            $this->fieldRepository->deleteByLeague($league->id);
            $this->leagueRepository->delete($league->id);
        });
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
