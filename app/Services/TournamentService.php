<?php

namespace App\Services;

use App\Contracts\Repositories\RegistrationRepositoryInterface;
use App\Contracts\Repositories\RosterRepositoryInterface;
use App\Contracts\Repositories\TournamentRepositoryInterface;
use App\Contracts\Services\TournamentServiceInterface;
use App\Core\BaseService;
use App\Core\Classes\ListQuery;
use App\Core\Enum\Message;
use App\Core\Enum\Sport;
use App\Core\Enum\TournamentGender;
use App\Core\Enum\TournamentStatus;
use App\Data\Tournaments\TournamentData;
use App\Exceptions\CustomErrorException;
use App\Models\League;
use App\Models\Tournament;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * @extends BaseService<Tournament>
 */
class TournamentService extends BaseService implements TournamentServiceInterface
{
    private const MIN_TEAMS_TO_START = 2;

    public function __construct(
        protected TournamentRepositoryInterface $tournamentRepository,
        protected RegistrationRepositoryInterface $registrationRepository,
        protected RosterRepositoryInterface $rosterRepository,
    ) {
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
        return $this->tournamentRepository->createInLeague(
            $league->id,
            array_merge($data->toArray(), ['sport' => ($data->sport ?? Sport::default())->value])
        );
    }

    public function updateTournament(Tournament $tournament, TournamentData $data): Tournament
    {
        $this->assertSportUnchangedWithRegistrations($tournament, $data);

        switch ($tournament->status) {
            case TournamentStatus::FINISHED:
                throw new CustomErrorException(Message::TOURNAMENT_FINISHED_NOT_EDITABLE, Response::HTTP_UNPROCESSABLE_ENTITY);
            case TournamentStatus::IN_PROGRESS:
                // Género y edades ya afectan a los rosters: el resto de campos debe llegar sin cambios.
                if ($this->changesLockedFields($tournament, $data)) {
                    throw new CustomErrorException(Message::TOURNAMENT_IN_PROGRESS_ONLY_NAME, Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                // Los campos de plantilla se editan mientras la liguilla no haya iniciado.
                if ($this->changesRosterFields($tournament, $data) && $tournament->playoffsStarted()) {
                    throw new CustomErrorException(Message::TOURNAMENT_PLAYOFFS_STARTED_LOCKED, Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                $this->assertRosterSizeFits($tournament, $data);

                return $this->tournamentRepository->updateModel($tournament, [
                    'name' => $data->name,
                    'max_roster_size' => $data->max_roster_size,
                    'min_matches_for_playoffs' => $data->min_matches_for_playoffs,
                ]);
            default:
                $this->assertRosterSizeFits($tournament, $data);

                return $this->tournamentRepository->updateModel(
                    $tournament,
                    array_merge($data->toArray(), ['sport' => ($data->sport ?? $tournament->sport)->value])
                );
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

        // TODO: al existir partidos, validar aquí el resto de precondiciones para iniciar.
        if ($status === TournamentStatus::IN_PROGRESS
            && $this->participatingTeams($tournament) < self::MIN_TEAMS_TO_START) {
            throw new CustomErrorException(Message::TOURNAMENT_START_NEEDS_TEAMS, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->tournamentRepository->updateStatus($tournament, $status);
    }

    public function deleteTournament(Tournament $tournament): void
    {
        if ($tournament->status !== TournamentStatus::PLANNING) {
            throw new CustomErrorException(Message::TOURNAMENT_DELETE_ONLY_PLANNING, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        DB::transaction(function () use ($tournament) {
            $this->registrationRepository->deleteByTournament($tournament->id);
            $this->delete($tournament->id);
        });
    }

    /**
     * Equipos inscritos que juegan: los vetados conservan su inscripción pero no cuentan.
     */
    private function participatingTeams(Tournament $tournament): int
    {
        return $this->registrationRepository->countActiveByTournament($tournament->id)
            - $this->registrationRepository->countBannedTeamsByTournament($tournament->id);
    }

    /**
     * Sin `sport` en el body se conserva el actual. Con equipos inscritos (activos o vetados) no cambia.
     */
    private function assertSportUnchangedWithRegistrations(Tournament $tournament, TournamentData $data): void
    {
        if ($data->sport !== null
            && $data->sport !== $tournament->sport
            && $this->registrationRepository->countActiveByTournament($tournament->id) > 0) {
            throw new CustomErrorException(Message::TOURNAMENT_SPORT_LOCKED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function changesRosterFields(Tournament $tournament, TournamentData $data): bool
    {
        return $tournament->max_roster_size !== $data->max_roster_size
            || $tournament->min_matches_for_playoffs !== $data->min_matches_for_playoffs;
    }

    /**
     * El máximo no puede quedar por debajo de los jugadores (activos y pendientes) que ya tiene algún equipo.
     */
    private function assertRosterSizeFits(Tournament $tournament, TournamentData $data): void
    {
        if ($data->max_roster_size === null) {
            return;
        }

        $largest = $this->rosterRepository->largestOpenRoster($tournament->id);

        if ($largest !== null && $largest['players'] > $data->max_roster_size) {
            throw new CustomErrorException(
                Message::getMessageMaxRosterBelowCurrent($data->max_roster_size, $largest['team'], $largest['players']),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
    }

    private function changesLockedFields(Tournament $tournament, TournamentData $data): bool
    {
        return $tournament->gender !== $data->gender
            || $tournament->min_age !== $data->min_age
            || $tournament->max_age !== $data->max_age
            || $tournament->start_date->format('Y-m-d') !== $data->start_date;
    }
}
