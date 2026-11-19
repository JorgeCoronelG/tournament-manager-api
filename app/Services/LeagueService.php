<?php

namespace App\Services;

use App\Contracts\Repositories\FieldRepositoryInterface;
use App\Contracts\Repositories\LeagueRepositoryInterface;
use App\Contracts\Repositories\TournamentRepositoryInterface;
use App\Contracts\Services\LeagueServiceInterface;
use App\Core\BaseService;
use App\Core\Classes\ListQuery;
use App\Core\Enum\LeagueStatus;
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
use Spatie\LaravelData\Optional;
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
        ] + $this->limitAttributes($data));

        return $league->load('admin');
    }

    /**
     * Límites del body (null = sin límite). `max_teams_per_tournament` (reservado para los planes futuros) solo cambia si viene.
     *
     * @return array<string, mixed>
     */
    private function limitAttributes(LeagueData $data): array
    {
        return ['max_active_tournaments' => $data->max_active_tournaments]
            + ($data->max_teams_per_tournament instanceof Optional ? [] : ['max_teams_per_tournament' => $data->max_teams_per_tournament]);
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
        ] + $this->limitAttributes($data));

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

    public function limits(League $league): array
    {
        $tournaments = $this->tournamentRepository->usageByLeague($league->id);
        $rows = [];

        foreach ($tournaments as $tournament) {
            $registrations = $tournament->getAttributeValue('active_registrations');
            $rows[] = [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'status' => $tournament->status->value,
                'active_registrations' => is_numeric($registrations) ? (int) $registrations : 0,
            ];
        }

        return [
            'max_active_tournaments' => $league->max_active_tournaments,
            'max_teams_per_tournament' => $league->max_teams_per_tournament,
            'usage' => [
                'active_tournaments' => $tournaments->whereIn('status', [TournamentStatus::IN_PROGRESS, TournamentStatus::PLAYOFFS])->count(),
                'tournaments' => $rows,
            ],
        ];
    }

    public function paginateAccessible(ListQuery $query, User $user, ?string $search): LengthAwarePaginator
    {
        $isSuperadmin = $user->roles->contains('id', RoleEnum::SUPERADMIN->value);

        // El encargado no ve las ligas inactivas; el superadmin las ve todas para poder reactivarlas.
        return $this->leagueRepository->paginateAccessible($query, $isSuperadmin ? null : $user->id, $search, $isSuperadmin ? null : LeagueStatus::ACTIVE);
    }

    public function paginate(ListQuery $query, ?int $adminUserId, ?string $search, ?LeagueStatus $status = null): LengthAwarePaginator
    {
        return $this->leagueRepository->paginateManaged($query, $adminUserId, $search, $status);
    }

    public function updateStatus(int $id, bool $isActive): League
    {
        /** @var League $league */
        $league = $this->leagueRepository->findById($id);
        $target = $isActive ? LeagueStatus::ACTIVE : LeagueStatus::INACTIVE;

        if ($league->status !== $target) {
            if (! $isActive && $this->tournamentRepository->countByLeagueAndStatuses($league->id, [TournamentStatus::IN_PROGRESS, TournamentStatus::PLAYOFFS]) > 0) {
                throw new CustomErrorException(Message::LEAGUE_DEACTIVATE_HAS_ACTIVE_TOURNAMENTS, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            /** @var League $league */
            $league = $this->leagueRepository->update($id, ['status' => $target]);
        }

        return $league->load('admin');
    }
}
