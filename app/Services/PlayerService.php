<?php

namespace App\Services;

use App\Contracts\Repositories\PlayerRepositoryInterface;
use App\Contracts\Services\PlayerServiceInterface;
use App\Core\BaseService;
use App\Core\Enum\Message;
use App\Core\Enum\PlayerGender;
use App\Exceptions\CustomErrorException;
use App\Models\League;
use App\Models\Player;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * @extends BaseService<Player>
 */
class PlayerService extends BaseService implements PlayerServiceInterface
{
    public function __construct(protected PlayerRepositoryInterface $playerRepository)
    {
        parent::__construct($playerRepository);
    }

    public function changeGender(League $league, Player $player, PlayerGender $gender, User $changedBy): Player
    {
        return DB::transaction(function () use ($league, $player, $gender, $changedBy) {
            $locked = $this->playerRepository->lockWithRosters($player->id);

            if (! $this->playerRepository->hasRosterInLeague($locked->id, $league->id)) {
                throw new CustomErrorException(Message::PLAYER_NOT_FOUND, Response::HTTP_NOT_FOUND);
            }

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

            // TODO: al existir las solicitudes de roster, cancelar aquí las pendientes incompatibles con la nueva rama.
            $this->playerRepository->updateGender($locked, $gender);
            $this->playerRepository->recordGenderChange($locked, $changedBy->id, $current, $gender);

            return $locked->load('user');
        });
    }
}
