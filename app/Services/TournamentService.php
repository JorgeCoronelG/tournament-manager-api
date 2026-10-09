<?php

namespace App\Services;

use App\Contracts\Repositories\TournamentRepositoryInterface;
use App\Contracts\Services\TournamentServiceInterface;
use App\Core\BaseService;
use App\Core\Classes\ListQuery;
use App\Core\Enum\Message;
use App\Core\Enum\TournamentGender;
use App\Core\Enum\TournamentStatus;
use App\Data\Tournaments\TournamentData;
use App\Exceptions\CustomErrorException;
use App\Models\League;
use App\Models\Tournament;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\Response;

/**
 * @extends BaseService<Tournament>
 */
class TournamentService extends BaseService implements TournamentServiceInterface
{
    public function __construct(protected TournamentRepositoryInterface $tournamentRepository)
    {
        parent::__construct($tournamentRepository);
    }

    public function paginateByLeague(
        League $league,
        ListQuery $query,
        ?string $search,
        ?TournamentGender $gender,
        ?TournamentStatus $status
    ): LengthAwarePaginator {
        return $this->tournamentRepository->paginateByLeague($league->id, $query, $search, $gender, $status);
    }

    public function createInLeague(League $league, TournamentData $data): Tournament
    {
        return $this->tournamentRepository->createInLeague($league->id, $data->toArray());
    }

    public function updateTournament(Tournament $tournament, TournamentData $data): Tournament
    {
        switch ($tournament->status) {
            case TournamentStatus::FINISHED:
                throw new CustomErrorException(Message::TOURNAMENT_FINISHED_NOT_EDITABLE, Response::HTTP_UNPROCESSABLE_ENTITY);
            case TournamentStatus::IN_PROGRESS:
                // Género y edades ya afectan a los rosters: el resto de campos debe llegar sin cambios.
                if ($this->changesLockedFields($tournament, $data)) {
                    throw new CustomErrorException(Message::TOURNAMENT_IN_PROGRESS_ONLY_NAME, Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                return $this->tournamentRepository->updateModel($tournament, ['name' => $data->name]);
            default:
                return $this->tournamentRepository->updateModel($tournament, $data->toArray());
        }
    }

    public function changeStatus(Tournament $tournament, TournamentStatus $status): Tournament
    {
        if ($tournament->status->next() !== $status) {
            throw new CustomErrorException(
                Message::getMessageInvalidTournamentTransition($tournament->status->label(), $status->label()),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // TODO: al existir equipos y partidos, validar aquí las precondiciones para iniciar (p. ej. equipos aprobados).
        return $this->tournamentRepository->updateStatus($tournament, $status);
    }

    public function deleteTournament(Tournament $tournament): void
    {
        // TODO: al existir inscripciones, bloquear también si el torneo tiene equipos inscritos.
        if ($tournament->status !== TournamentStatus::PLANNING) {
            throw new CustomErrorException(Message::TOURNAMENT_DELETE_ONLY_PLANNING, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->delete($tournament->id);
    }

    private function changesLockedFields(Tournament $tournament, TournamentData $data): bool
    {
        return $tournament->gender !== $data->gender
            || $tournament->min_age !== $data->min_age
            || $tournament->max_age !== $data->max_age
            || $tournament->start_date->format('Y-m-d') !== $data->start_date;
    }
}
