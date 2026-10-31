<?php

namespace App\Services;

use App\Contracts\Repositories\MatchRepositoryInterface;
use App\Contracts\Repositories\RegistrationRepositoryInterface;
use App\Contracts\Repositories\RosterRepositoryInterface;
use App\Contracts\Services\RegistrationChangeServiceInterface;
use App\Core\Enum\Message;
use App\Core\Enum\RegistrationStatus;
use App\Core\Enum\TeamStatus;
use App\Core\Enum\TournamentStatus;
use App\Events\TeamReplaced;
use App\Events\TeamWithdrawn;
use App\Exceptions\CustomErrorException;
use App\Helpers\Names;
use App\Models\Registration;
use App\Models\RegistrationTeamChange;
use App\Models\Tournament;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class RegistrationChangeService implements RegistrationChangeServiceInterface
{
    public function __construct(
        protected RegistrationRepositoryInterface $registrationRepository,
        protected MatchRepositoryInterface $matchRepository,
        protected RosterRepositoryInterface $rosterRepository,
    ) {}

    public function withdrawPreview(Tournament $tournament, Registration $registration): array
    {
        $this->assertCanWithdraw($tournament, $registration);

        $played = $this->matchRepository->countPlayedOfRegistration($tournament->id, $registration->id);
        $team = $registration->team;
        $open = $this->rosterRepository->countOpenByRegistration($registration->id);

        return [
            'kept_played' => $played,
            'pending_matches' => $this->matchRepository->pendingOfRegistration($tournament->id, $registration->id)->count(),
            'players_released' => $open['active'] + $open['pending'],
            'warnings' => $played > 0 && $team !== null ? [Message::getMessageWithdrawKeepsResults($team->name, $played)] : [],
        ];
    }

    public function withdraw(Tournament $tournament, Registration $registration, string $reason, int $userId): array
    {
        $result = DB::transaction(function () use ($tournament, $registration, $reason, $userId) {
            // Con el torneo bloqueado, dos bajas simultáneas no se cruzan.
            $this->registrationRepository->lockTournament($tournament->id);
            $registration->refresh();
            $this->assertCanWithdraw($tournament->refresh(), $registration);

            $this->registrationRepository->markWithdrawn($registration, trim($reason), $userId);

            return [
                'kept_played' => $this->matchRepository->countPlayedOfRegistration($tournament->id, $registration->id),
                'pending_matches' => $this->matchRepository->pendingOfRegistration($tournament->id, $registration->id)->count(),
                'players_released' => $this->rosterRepository->releaseOpenByRegistration($registration->id),
                'registration' => $registration->load(['team.manager', 'group']),
            ];
        });

        TeamWithdrawn::dispatch($result['registration'], $userId);

        return $result;
    }

    public function replace(Tournament $tournament, Registration $registration, int $teamId, string $reason, int $userId): array
    {
        $result = DB::transaction(function () use ($tournament, $registration, $teamId, $reason, $userId) {
            $locked = $this->registrationRepository->lockTournament($tournament->id);
            $registration->refresh();

            if ($locked->status === TournamentStatus::PLAYOFFS) {
                throw new CustomErrorException(Message::REGISTRATION_REPLACE_PLAYOFFS, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if ($locked->status !== TournamentStatus::IN_PROGRESS) {
                throw new CustomErrorException(Message::REGISTRATION_REPLACE_ONLY_IN_PROGRESS, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $oldTeamId = $registration->team_id;
            $this->assertTeamCanReplace($locked, $registration, $teamId);

            $this->registrationRepository->replaceTeam($registration, $teamId, trim($reason), $userId);
            $this->rosterRepository->releaseOpenByRegistration($registration->id);

            return ['registration' => $registration->load(['team.manager', 'group']), 'old_team_id' => $oldTeamId];
        });

        TeamReplaced::dispatch($result['registration'], $result['old_team_id'], $teamId, $userId);

        return ['registration' => $result['registration'], 'warnings' => [Message::REGISTRATION_REPLACE_ROSTER_WARNING]];
    }

    public function teamChanges(Registration $registration): array
    {
        return array_values($this->registrationRepository->teamChanges($registration->id)->map(fn (RegistrationTeamChange $change) => [
            'id' => $change->id,
            'old_team' => ['id' => (int) $change->old_team_id, 'name' => $change->oldTeam->name ?? ''],
            'new_team' => ['id' => (int) $change->new_team_id, 'name' => $change->newTeam->name ?? ''],
            'reason' => $change->reason,
            'changed_by' => $change->changedBy === null ? null : ['id' => $change->changedBy->id, 'name' => trim($change->changedBy->first_name.' '.$change->changedBy->last_name)],
            'created_at' => $change->created_at->toIso8601String(),
        ])->all());
    }

    /**
     * @throws CustomErrorException
     */
    private function assertCanWithdraw(Tournament $tournament, Registration $registration): void
    {
        if (! in_array($tournament->status, [TournamentStatus::IN_PROGRESS, TournamentStatus::PLAYOFFS], true)) {
            throw new CustomErrorException(Message::REGISTRATION_WITHDRAW_ONLY_ACTIVE_TOURNAMENT, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($registration->status === RegistrationStatus::WITHDRAWN) {
            throw new CustomErrorException(Message::REGISTRATION_ALREADY_WITHDRAWN, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * El equipo nuevo debe ser de la liga y del deporte del torneo, estar activo, no tener ya una inscripción en el
     * torneo y tener un nombre que no use otra inscripción activa (el del equipo que sale queda libre).
     *
     * @throws ValidationException
     */
    private function assertTeamCanReplace(Tournament $tournament, Registration $registration, int $teamId): void
    {
        $team = $this->registrationRepository->findTeamsInLeague($tournament->league_id, [$teamId])->get($teamId);

        $error = null;

        if ($team === null) {
            $error = Message::REGISTRATION_TEAM_NOT_IN_LEAGUE;
        } elseif ($team->status === TeamStatus::BANNED) {
            $error = Message::REGISTRATION_TEAM_BANNED;
        } elseif ($team->sport !== $tournament->sport) {
            $error = Message::REGISTRATION_SPORT_MISMATCH;
        } elseif ($this->registrationRepository->teamHasAnyRegistration($tournament->id, $teamId)) {
            $error = Message::REGISTRATION_TEAM_ALREADY_REGISTERED;
        } else {
            $leaving = $registration->team?->name;
            $taken = array_map(Names::normalize(...), array_values(array_filter(
                $this->registrationRepository->activeTeamNames($tournament->id),
                fn (string $name) => $leaving === null || Names::normalize($name) !== Names::normalize($leaving)
            )));

            if (in_array(Names::normalize($team->name), $taken, true)) {
                $error = Message::REGISTRATION_TEAM_NAME_TAKEN;
            }
        }

        if ($error !== null) {
            throw ValidationException::withMessages(['team_id' => $error]);
        }
    }
}
