<?php

namespace App\Services;

use App\Contracts\Repositories\LeagueRefereeRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Contracts\Services\LeagueRefereeServiceInterface;
use App\Contracts\Services\UserServiceInterface;
use App\Core\BaseService;
use App\Core\Classes\ListQuery;
use App\Core\Enum\LeagueRefereeStatus;
use App\Core\Enum\Message;
use App\Core\Enum\Role as RoleEnum;
use App\Data\Referees\RefereeData;
use App\Data\Users\CreateUserData;
use App\Exceptions\CustomErrorException;
use App\Models\League;
use App\Models\LeagueReferee;
use App\Models\User;
use App\Notifications\RefereeAddedToLeagueNotification;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * @extends BaseService<LeagueReferee>
 */
class LeagueRefereeService extends BaseService implements LeagueRefereeServiceInterface
{
    public function __construct(
        protected LeagueRefereeRepositoryInterface $leagueRefereeRepository,
        protected UserRepositoryInterface $userRepository,
        protected UserServiceInterface $userService,
    ) {
        parent::__construct($leagueRefereeRepository);
    }

    public function paginateByLeague(League $league, ListQuery $query, ?string $search, ?LeagueRefereeStatus $status): LengthAwarePaginator
    {
        return $this->leagueRefereeRepository->paginateByLeague($league->id, $query, $search, $status);
    }

    public function createReferee(League $league, RefereeData $data): LeagueReferee
    {
        return DB::transaction(function () use ($league, $data) {
            // El rol es siempre referee: nunca sale del body.
            $user = $this->userService->create(new CreateUserData(
                $data->first_name,
                $data->last_name,
                $data->email,
                $data->phone,
                [RoleEnum::REFEREE->value],
            ));

            $link = $this->leagueRefereeRepository->createLink($league->id, $user->id);

            return $link->setRelation('user', $user);
        });
    }

    public function lookup(League $league, string $userCode): array
    {
        $user = $this->findEligibleOrFail($userCode);
        $link = $this->leagueRefereeRepository->findLinkWithTrashed($league->id, $user->id);

        return [
            'user' => $user,
            'already_in_league' => $link !== null && ! $link->trashed() && $link->status === LeagueRefereeStatus::ACTIVE,
        ];
    }

    public function attach(League $league, string $userCode): LeagueReferee
    {
        $user = $this->findEligibleOrFail($userCode);
        $link = $this->leagueRefereeRepository->findLinkWithTrashed($league->id, $user->id);

        if ($link === null) {
            $link = $this->leagueRefereeRepository->createLink($league->id, $user->id);
        } elseif ($link->trashed()) {
            $link = $this->leagueRefereeRepository->restoreLink($link, LeagueRefereeStatus::ACTIVE);
        } elseif ($link->status === LeagueRefereeStatus::INACTIVE) {
            $link = $this->leagueRefereeRepository->updateStatus($link, LeagueRefereeStatus::ACTIVE);
        } else {
            throw new CustomErrorException(Message::REFEREE_ALREADY_IN_LEAGUE, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Solo una cuenta ya activada recibe el aviso; la pendiente aún no puede ver la liga.
        if ($user->email_verified_at !== null) {
            $user->notify(new RefereeAddedToLeagueNotification($league));
        }

        return $link->setRelation('user', $user);
    }

    public function changeStatus(League $league, int $userId, LeagueRefereeStatus $status): LeagueReferee
    {
        $link = $this->leagueRefereeRepository->findLinked($league->id, $userId);

        return $this->leagueRefereeRepository->updateStatus($link, $status)->load('user');
    }

    public function detach(League $league, int $userId): void
    {
        // TODO: al existir partidos, bloquear si el árbitro tiene partidos asignados en esta liga.
        $link = $this->leagueRefereeRepository->findLinked($league->id, $userId);

        $this->delete($link->id);
    }

    public function updateReferee(League $league, int $userId, RefereeData $data): LeagueReferee
    {
        $link = $this->leagueRefereeRepository->findLinked($league->id, $userId);
        /** @var User $user */
        $user = $link->user;

        $isPending = $user->email_verified_at === null;
        $onlyHere = $this->leagueRefereeRepository->countInOtherLeagues($user->id, $league->id) === 0;

        if (! $isPending || ! $onlyHere) {
            throw new CustomErrorException(Message::REFEREE_EDIT_NOT_ALLOWED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $this->userService->updateProfile($user, $data->first_name, $data->last_name, $data->email, $data->phone);

        return $link->setRelation('user', $user);
    }

    public function resendInvitation(League $league, int $userId): LeagueReferee
    {
        $link = $this->leagueRefereeRepository->findLinked($league->id, $userId);

        $this->userService->resendInvitation($userId);

        return $link;
    }

    /**
     * Mismo 404 genérico para código inexistente, sin rol referee o inactivo: no revela qué códigos existen.
     *
     * @throws CustomErrorException
     */
    private function findEligibleOrFail(string $userCode): User
    {
        return $this->userRepository->findEligibleReferee($userCode)
            ?? throw new CustomErrorException(Message::MODEL_NOT_FOUND_EXCEPTION, Response::HTTP_NOT_FOUND);
    }
}
