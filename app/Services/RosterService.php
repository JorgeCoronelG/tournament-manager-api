<?php

namespace App\Services;

use App\Contracts\Repositories\RosterRepositoryInterface;
use App\Contracts\Services\RosterServiceInterface;
use App\Core\BaseService;
use App\Core\Classes\ListQuery;
use App\Core\Enum\Message;
use App\Core\Enum\RosterStatus;
use App\Core\Enum\TournamentStatus;
use App\Exceptions\CustomErrorException;
use App\Models\League;
use App\Models\Player;
use App\Models\Registration;
use App\Models\Roster;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Policies\LeaguePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * @extends BaseService<Roster>
 */
class RosterService extends BaseService implements RosterServiceInterface
{
    public function __construct(
        protected RosterRepositoryInterface $rosterRepository,
        protected PlayerEligibility $eligibility,
    ) {
        parent::__construct($rosterRepository);
    }

    public function requestPlayer(Registration $registration, Player $player, int $jerseyNumber, User $actor): Roster
    {
        return DB::transaction(function () use ($registration, $player, $jerseyNumber, $actor) {
            $team = Team::query()->withTrashed()->findOrFail($registration->team_id);
            $tournament = Tournament::query()->findOrFail($registration->tournament_id);

            if ($team->manager_user_id !== $actor->id) {
                throw $this->forbidden();
            }

            $this->assertRostersOpen($tournament);

            $player = $player->newQuery()->lockForUpdate()->findOrFail($player->id);
            $this->eligibility->assertEligible($player, $tournament);

            if ($this->rosterRepository->playerBannedInLeague($player->id, $tournament->league_id)) {
                throw $this->unavailable();
            }

            $this->assertPlayerCanJoin($registration, $player, $tournament);
            $this->assertRosterHasRoom($registration, $tournament, $player, $jerseyNumber);

            return $this->rosterRepository->createRequest($registration->id, $tournament->id, $player->id, $jerseyNumber, $actor->id);
        });
    }

    public function accept(Roster $roster, User $actor): Roster
    {
        return DB::transaction(function () use ($roster, $actor) {
            $roster = $this->lockPendingOf($roster, $actor);
            $this->assertRostersOpen($this->tournamentOf($roster));

            // Con las filas del jugador en el torneo bloqueadas, dos aceptaciones simultáneas no se cruzan.
            $this->rosterRepository->lockByPlayerInTournament($roster->player_id, $roster->tournament_id);

            $accepted = $this->rosterRepository->accept($roster);
            $this->rosterRepository->cancelPendingOfPlayer($roster->player_id, $roster->tournament_id, $roster->id);

            return $accepted;
        });
    }

    public function reject(Roster $roster, User $actor): Roster
    {
        return DB::transaction(fn () => $this->rosterRepository->reject($this->lockPendingOf($roster, $actor)));
    }

    public function removePlayer(Roster $roster, User $actor, string $reason): Roster
    {
        return DB::transaction(function () use ($roster, $actor, $reason) {
            $roster = $this->rosterRepository->lockById($roster->id);
            $tournament = $this->tournamentOf($roster);
            $this->assertLeagueAdmin($tournament, $actor);

            $reason = trim($reason);

            if ($reason === '' || mb_strlen($reason) > 255) {
                throw ValidationException::withMessages(['removal_reason' => Message::ROSTER_REMOVAL_REASON_REQUIRED]);
            }

            if ($roster->status !== RosterStatus::ACTIVE) {
                throw new CustomErrorException(Message::ROSTER_REMOVE_ONLY_ACTIVE, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if (! in_array($tournament->status, [TournamentStatus::PLANNING, TournamentStatus::IN_PROGRESS], true)) {
                throw new CustomErrorException(Message::ROSTER_REMOVE_TOURNAMENT_FINISHED, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if ($tournament->playoffsStarted()) {
                throw new CustomErrorException(Message::ROSTER_REMOVE_PLAYOFFS_STARTED, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->rosterRepository->remove($roster, $actor->id, $reason);
        });
    }

    public function authorizeRejoin(Roster $roster, User $actor): Roster
    {
        return DB::transaction(function () use ($roster, $actor) {
            $roster = $this->rosterRepository->lockById($roster->id);
            $this->assertLeagueAdmin($this->tournamentOf($roster), $actor);

            if ($roster->status !== RosterStatus::REMOVED) {
                throw new CustomErrorException(Message::ROSTER_REJOIN_ONLY_REMOVED, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->rosterRepository->authorizeRejoin($roster);
        });
    }

    public function revokeRejoin(Roster $roster, User $actor): Roster
    {
        return DB::transaction(function () use ($roster, $actor) {
            $roster = $this->rosterRepository->lockById($roster->id);
            $this->assertLeagueAdmin($this->tournamentOf($roster), $actor);

            if ($roster->rejoin_authorized_at === null) {
                throw new CustomErrorException(Message::ROSTER_REJOIN_NOT_AUTHORIZED, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $this->rosterRepository->lockByPlayerInTournament($roster->player_id, $roster->tournament_id);

            if ($this->rosterRepository->hasNewerRowOfPlayer($roster)) {
                throw new CustomErrorException(Message::ROSTER_REJOIN_ALREADY_USED, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->rosterRepository->revokeRejoin($roster);
        });
    }

    public function listForRegistration(Registration $registration, ListQuery $query, ?string $search, ?RosterStatus $status): array
    {
        $tournament = Tournament::query()->findOrFail($registration->tournament_id);

        return [
            'rows' => $this->rosterRepository->paginateByRegistration($registration->id, $query, $search, $status),
            'summary' => $this->rosterRepository->countOpenByRegistration($registration->id) + ['max_roster_size' => $tournament->max_roster_size],
        ];
    }

    public function releaseBannedPlayer(Player $player, League $league, User $actor, string $reason): array
    {
        return DB::transaction(fn () => $this->rosterRepository->releasePlayerInLeague($player->id, $league->id, $actor->id, trim($reason)));
    }

    public function closeRosters(Tournament $tournament): int
    {
        return $this->rosterRepository->cancelPendingOfTournament($tournament->id);
    }

    /**
     * Plantillas abiertas: torneo en planeación o en curso y sin liguilla.
     */
    private function assertRostersOpen(Tournament $tournament): void
    {
        $open = in_array($tournament->status, [TournamentStatus::PLANNING, TournamentStatus::IN_PROGRESS], true)
            && ! $tournament->playoffsStarted();

        if (! $open) {
            throw new CustomErrorException(Message::ROSTER_CLOSED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * El jugador no puede estar ya activo en el torneo, ni haber sido quitado de otro equipo sin autorización de
     * reingreso, ni tener una solicitud abierta con este mismo equipo.
     */
    private function assertPlayerCanJoin(Registration $registration, Player $player, Tournament $tournament): void
    {
        $rows = $this->rosterRepository->lockByPlayerInTournament($player->id, $tournament->id);

        if ($rows->contains(fn (Roster $row) => $row->status === RosterStatus::ACTIVE)) {
            throw $this->unavailable();
        }

        $removedWithoutLeave = $rows->contains(fn (Roster $row) => $row->status === RosterStatus::REMOVED
            && $row->registration_id !== $registration->id
            && $row->rejoin_authorized_at === null);

        if ($removedWithoutLeave) {
            throw $this->unavailable();
        }

        if ($rows->contains(fn (Roster $row) => $row->registration_id === $registration->id && $row->status->isOpen())) {
            throw new CustomErrorException(Message::ROSTER_REQUEST_DUPLICATE, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function assertRosterHasRoom(Registration $registration, Tournament $tournament, Player $player, int $jerseyNumber): void
    {
        $open = $this->rosterRepository->lockOpenByRegistration($registration->id);

        if ($tournament->max_roster_size !== null && $open->count() >= $tournament->max_roster_size) {
            throw new CustomErrorException(Message::ROSTER_FULL, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($open->contains(fn (Roster $row) => $row->jersey_number === $jerseyNumber)) {
            throw new CustomErrorException(Message::ROSTER_JERSEY_TAKEN, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * Fila pending bloqueada, que responde su propio jugador.
     */
    private function lockPendingOf(Roster $roster, User $actor): Roster
    {
        $roster = $this->rosterRepository->lockById($roster->id);

        if (Player::query()->findOrFail($roster->player_id)->user_id !== $actor->id) {
            throw $this->forbidden();
        }

        if ($roster->status !== RosterStatus::PENDING) {
            throw new CustomErrorException(Message::ROSTER_NOT_PENDING, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $roster;
    }

    private function assertLeagueAdmin(Tournament $tournament, User $actor): void
    {
        $league = League::query()->findOrFail($tournament->league_id);

        if (! (new LeaguePolicy)->access($actor->loadMissing('roles'), $league)) {
            throw $this->forbidden();
        }
    }

    private function tournamentOf(Roster $roster): Tournament
    {
        return Tournament::query()->findOrFail($roster->tournament_id);
    }

    private function forbidden(): CustomErrorException
    {
        return new CustomErrorException(Message::AUTHORIZATION_EXCEPTION, Response::HTTP_FORBIDDEN);
    }

    private function unavailable(): CustomErrorException
    {
        return new CustomErrorException(Message::ROSTER_PLAYER_UNAVAILABLE, Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
