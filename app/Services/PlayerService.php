<?php

namespace App\Services;

use App\Contracts\Repositories\PlayerRepositoryInterface;
use App\Contracts\Services\PlayerServiceInterface;
use App\Contracts\Services\RosterServiceInterface;
use App\Core\BaseService;
use App\Core\Classes\ListQuery;
use App\Core\Enum\Message;
use App\Core\Enum\PlayerGender;
use App\Core\Enum\Role as RoleEnum;
use App\Exceptions\CustomErrorException;
use App\Models\League;
use App\Models\Player;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * @extends BaseService<Player>
 */
class PlayerService extends BaseService implements PlayerServiceInterface
{
    public function __construct(
        protected PlayerRepositoryInterface $playerRepository,
        protected RosterServiceInterface $rosterService,
    ) {
        parent::__construct($playerRepository);
    }

    public function paginateByLeague(League $league, User $actor, ListQuery $query, ?string $search, ?PlayerGender $gender, ?bool $banned): LengthAwarePaginator
    {
        return $this->playerRepository->paginateForLeague($league->id, ! $this->isSuperadmin($actor), $query, $search, $gender, $banned);
    }

    public function showInLeague(League $league, Player $player, User $actor): Player
    {
        $this->assertVisible($league, $player, $actor);

        return $this->playerRepository->loadDetailForLeague($player, $league->id);
    }

    public function ban(League $league, Player $player, User $actor, string $reason): array
    {
        $result = DB::transaction(function () use ($league, $player, $actor, $reason) {
            $locked = $this->playerRepository->lockWithRosters($player->id);
            $this->assertVisible($league, $locked, $actor);

            if ($this->playerRepository->lockActiveBan($locked->id, $league->id) !== null) {
                throw new CustomErrorException(Message::PLAYER_ALREADY_BANNED, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $reason = trim($reason);
            $this->playerRepository->createBan($locked->id, $league->id, $actor->id, $reason);

            return $this->rosterService->releaseBannedPlayer($locked, $league, $actor, $reason);
        });

        return ['player' => $this->playerRepository->loadForLeague($player, $league->id)] + $result;
    }

    public function unban(League $league, Player $player, User $actor): Player
    {
        DB::transaction(function () use ($league, $player, $actor) {
            $locked = $this->playerRepository->lockWithRosters($player->id);
            $this->assertVisible($league, $locked, $actor);

            $ban = $this->playerRepository->lockActiveBan($locked->id, $league->id)
                ?? throw new CustomErrorException(Message::PLAYER_NOT_BANNED, Response::HTTP_UNPROCESSABLE_ENTITY);

            $this->playerRepository->liftBan($ban, $actor->id);
        });

        return $this->playerRepository->loadForLeague($player, $league->id);
    }

    public function changeGender(League $league, Player $player, PlayerGender $gender, User $changedBy): Player
    {
        return DB::transaction(function () use ($league, $player, $gender, $changedBy) {
            $locked = $this->playerRepository->lockWithRosters($player->id);
            $this->assertVisible($league, $locked, $changedBy);

            $current = $locked->gender;

            if ($current === $gender) {
                return $locked->load('user');
            }

            $blocking = $this->playerRepository->openTournamentsWithActiveRoster($locked->id)
                ->filter(fn ($tournament) => ! $tournament->gender->accepts($gender));

            if ($blocking->isNotEmpty()) {
                throw new CustomErrorException(
                    Message::getMessagePlayerGenderLocked($blocking->pluck('name')->implode(', ')),
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            // Una solicitud pending incompatible no bloquea: se cancela para que el jugador no pueda aceptarla.
            $this->playerRepository->cancelPendingRostersNotAccepting($locked->id, $gender);
            $this->playerRepository->updateGender($locked, $gender);
            $this->playerRepository->recordGenderChange($locked, $changedBy->id, $current, $gender);

            return $locked->load('user');
        });
    }

    private function isSuperadmin(User $user): bool
    {
        return $user->loadMissing('roles')->roles->contains('id', RoleEnum::SUPERADMIN->value);
    }

    /**
     * El encargado solo ve a quienes tienen o tuvieron roster en su liga; el superadmin ve a todos.
     *
     * @throws CustomErrorException
     */
    private function assertVisible(League $league, Player $player, User $actor): void
    {
        if (! $this->isSuperadmin($actor) && ! $this->playerRepository->hasRosterInLeague($player->id, $league->id)) {
            throw new CustomErrorException(Message::PLAYER_NOT_FOUND, Response::HTTP_NOT_FOUND);
        }
    }
}
