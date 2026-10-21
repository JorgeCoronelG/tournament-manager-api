<?php

namespace App\Services;

use App\Contracts\Repositories\RegistrationRepositoryInterface;
use App\Contracts\Services\RegistrationServiceInterface;
use App\Core\BaseService;
use App\Core\Classes\ListQuery;
use App\Core\Enum\Message;
use App\Core\Enum\RegistrationStatus;
use App\Core\Enum\TeamStatus;
use App\Core\Enum\TournamentStatus;
use App\Exceptions\CustomErrorException;
use App\Helpers\Names;
use App\Models\Registration;
use App\Models\Tournament;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * @extends BaseService<Registration>
 */
class RegistrationService extends BaseService implements RegistrationServiceInterface
{
    public function __construct(protected RegistrationRepositoryInterface $registrationRepository)
    {
        parent::__construct($registrationRepository);
    }

    public function paginateByTournament(Tournament $tournament, ListQuery $query, ?string $search, ?RegistrationStatus $status): LengthAwarePaginator
    {
        return $this->registrationRepository->paginateByTournament($tournament->id, $query, $search, $status);
    }

    public function paginateAvailableTeams(Tournament $tournament, ListQuery $query, ?string $search): LengthAwarePaginator
    {
        return $this->registrationRepository->paginateAvailableTeams($tournament->league_id, $tournament->id, $query, $search);
    }

    public function countsByTournament(Tournament $tournament): array
    {
        return [
            'registered' => $this->registrationRepository->countActiveByTournament($tournament->id),
            'banned' => $this->registrationRepository->countBannedTeamsByTournament($tournament->id),
        ];
    }

    public function assertNoBannedTeams(Tournament $tournament): void
    {
        if ($this->registrationRepository->countBannedTeamsByTournament($tournament->id) > 0) {
            throw new CustomErrorException(Message::TOURNAMENT_SCHEDULE_HAS_BANNED_TEAMS, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function registerTeams(Tournament $tournament, array $teamIds): Collection
    {
        return DB::transaction(function () use ($tournament, $teamIds) {
            // Con el torneo bloqueado, el estado y los nombres que se leen no cambian hasta guardar.
            $locked = $this->registrationRepository->lockTournament($tournament->id);

            if ($locked->status !== TournamentStatus::PLANNING) {
                throw new CustomErrorException(Message::REGISTRATION_TOURNAMENT_NOT_PLANNING, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $this->assertTeamsCanRegister($locked, $teamIds);

            return new Collection(array_map(
                fn (int $teamId) => $this->registrationRepository->register($locked->id, $teamId)->load('team.manager'),
                $teamIds
            ));
        });
    }

    public function removeRegistration(Tournament $tournament, Registration $registration): void
    {
        if ($tournament->status !== TournamentStatus::PLANNING) {
            throw new CustomErrorException(Message::REGISTRATION_REMOVE_ONLY_PLANNING, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->delete($registration->id);
    }

    /**
     * @param  list<int>  $teamIds
     *
     * @throws ValidationException
     */
    private function assertTeamsCanRegister(Tournament $tournament, array $teamIds): void
    {
        $teams = $this->registrationRepository->findTeamsInLeague($tournament->league_id, $teamIds);
        $registered = $this->registrationRepository->registeredTeamIds($tournament->id, $teamIds);

        $takenNames = array_flip(array_map(Names::normalize(...), $this->registrationRepository->activeTeamNames($tournament->id)));
        $errors = [];

        foreach ($teamIds as $position => $teamId) {
            $team = $teams->get($teamId);
            $key = "team_ids.{$position}";

            // Inexistente, eliminado o de otra liga: un solo mensaje, para no revelar equipos ajenos.
            if ($team === null) {
                $errors[$key] = Message::REGISTRATION_TEAM_NOT_IN_LEAGUE;
            } elseif ($team->status === TeamStatus::BANNED) {
                $errors[$key] = Message::REGISTRATION_TEAM_BANNED;
            } elseif ($team->sport !== $tournament->sport) {
                $errors[$key] = Message::REGISTRATION_SPORT_MISMATCH;
            } elseif (in_array($teamId, $registered, true)) {
                $errors[$key] = Message::REGISTRATION_TEAM_ALREADY_REGISTERED;
            } elseif (isset($takenNames[Names::normalize($team->name)])) {
                $errors[$key] = Message::REGISTRATION_TEAM_NAME_TAKEN;
            } else {
                // Los equipos de esta misma petición también ocupan su nombre.
                $takenNames[Names::normalize($team->name)] = true;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
