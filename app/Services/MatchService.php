<?php

namespace App\Services;

use App\Contracts\Repositories\GroupRepositoryInterface;
use App\Contracts\Repositories\MatchRepositoryInterface;
use App\Contracts\Services\GroupServiceInterface;
use App\Contracts\Services\MatchServiceInterface;
use App\Contracts\Services\PlayoffServiceInterface;
use App\Core\BaseService;
use App\Core\Classes\ListQuery;
use App\Core\Enum\MatchPhase;
use App\Core\Enum\MatchStatus;
use App\Core\Enum\Message;
use App\Core\Enum\RegistrationStatus;
use App\Core\Enum\TournamentStatus;
use App\Core\Enum\WalkoverCause;
use App\Exceptions\CustomErrorException;
use App\Helpers\RoundRobin;
use App\Models\GameMatch;
use App\Models\Tournament;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * @extends BaseService<GameMatch>
 */
class MatchService extends BaseService implements MatchServiceInterface
{
    public function __construct(
        protected MatchRepositoryInterface $matchRepository,
        protected GroupRepositoryInterface $groupRepository,
        protected GroupServiceInterface $groupService,
        protected PlayoffServiceInterface $playoffService,
    ) {
        parent::__construct($matchRepository);
    }

    public function generate(Tournament $tournament, bool $shuffle): array
    {
        $this->assertInProgress($tournament, Message::MATCHES_GENERATE_ONLY_IN_PROGRESS);

        if ($this->matchRepository->hasPlayedRegular($tournament->id)) {
            throw new CustomErrorException(Message::MATCHES_REGENERATE_HAS_PLAYED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->groupService->assertMinMatchesFit($tournament, false);

        return DB::transaction(function () use ($tournament, $shuffle) {
            $this->matchRepository->deleteRegular($tournament->id);

            $now = now();
            $rows = [];
            $summary = [];

            foreach ($this->groupRepository->regularGroups($tournament->id) as $group) {
                $registrations = array_values($this->matchRepository->playingRegistrations($group->id)->map(fn ($registration) => (int) $registration->id)->all());

                if ($shuffle) {
                    shuffle($registrations);
                }

                $matchdays = RoundRobin::matchdays($registrations, $tournament->rounds);
                $perRound = RoundRobin::matchdaysPerRound(count($registrations));
                $matches = 0;

                foreach ($matchdays as $index => $pairs) {
                    foreach ($pairs as [$home, $away]) {
                        $rows[] = [
                            'tournament_id' => $tournament->id,
                            'group_id' => $group->id,
                            'home_registration_id' => $home,
                            'away_registration_id' => $away,
                            'phase' => MatchPhase::REGULAR_STAGE->value,
                            'matchday' => $index + 1,
                            'round_number' => $perRound > 0 && $index >= $perRound ? 2 : 1,
                            'status' => MatchStatus::SCHEDULED->value,
                            'schedule_locked' => false,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                        $matches++;
                    }
                }

                $summary[] = [
                    'group_id' => $group->id,
                    'group_name' => $group->name,
                    'teams' => count($registrations),
                    'matchdays' => count($matchdays),
                    'matches' => $matches,
                ];
            }

            $this->matchRepository->insertMany($rows);

            return ['groups' => $summary, 'total_matches' => count($rows)];
        });
    }

    public function registerWalkover(Tournament $tournament, GameMatch $match, int $loserRegistrationId, ?string $reason, ?int $userId): array
    {
        $this->assertResultsOpen($tournament, $match);
        $unprocessable = Response::HTTP_UNPROCESSABLE_ENTITY;

        if ($match->home_registration_id === null || $match->away_registration_id === null) {
            throw new CustomErrorException(Message::MATCH_NO_TEAMS, $unprocessable);
        }

        if (! in_array($loserRegistrationId, [$match->home_registration_id, $match->away_registration_id], true)) {
            throw ValidationException::withMessages(['loser_registration_id' => Message::WALKOVER_LOSER_INVALID]);
        }

        if ($match->status === MatchStatus::CANCELLED) {
            throw new CustomErrorException(Message::MATCH_CANCELLED, $unprocessable);
        }

        if ($match->status === MatchStatus::PLAYED) {
            if (! $match->is_walkover) {
                throw new CustomErrorException(Message::MATCH_ALREADY_PLAYED, $unprocessable);
            }

            if ($match->walkover_loser_registration_id !== $loserRegistrationId) {
                throw new CustomErrorException(Message::WALKOVER_OTHER_LOSER, $unprocessable);
            }

            // Mismo perdedor: no hay nada que cambiar.
            return ['match' => $match, 'tie' => $match->tie_id === null ? null : $this->playoffService->tieOf($match), 'advanced_to' => null];
        }

        $goals = $tournament->walkover_goals;
        // Si quien pierde está dado de baja, la causa es la baja del equipo; si no, no se presentó.
        $match->loadMissing(['home', 'away']);
        $loser = $match->home_registration_id === $loserRegistrationId ? $match->home : $match->away;
        $cause = $loser?->status === RegistrationStatus::WITHDRAWN ? WalkoverCause::TEAM_WITHDRAWN : WalkoverCause::NO_SHOW;

        if ($match->tie_id !== null) {
            return $this->playoffService->registerWalkover($match, $loserRegistrationId, $goals, $cause, $userId, $reason);
        }

        $homeLoses = $match->home_registration_id === $loserRegistrationId;
        $saved = $this->matchRepository->markWalkover($match, $homeLoses ? 0 : $goals, $homeLoses ? $goals : 0, $loserRegistrationId, $cause, $userId, $reason);

        return ['match' => $saved, 'tie' => null, 'advanced_to' => null];
    }

    public function recordResult(Tournament $tournament, GameMatch $match, int $homeScore, int $awayScore, ?int $homePenalties = null, ?int $awayPenalties = null): array
    {
        $this->assertResultsOpen($tournament, $match);

        if ($match->is_walkover) {
            throw new CustomErrorException(Message::MATCH_WALKOVER_UNDO_FIRST, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($match->status === MatchStatus::SCHEDULED && $this->hasWithdrawnTeam($match)) {
            throw new CustomErrorException(Message::MATCH_TEAM_WITHDRAWN, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($match->tie_id !== null) {
            return $this->playoffService->recordResult($match, $homeScore, $awayScore, $homePenalties, $awayPenalties);
        }

        if ($homePenalties !== null || $awayPenalties !== null) {
            throw new CustomErrorException(Message::MATCH_REGULAR_NO_PENALTIES, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return ['match' => $this->matchRepository->setResult($match, $homeScore, $awayScore), 'tie' => null, 'advanced_to' => null];
    }

    public function clearResult(Tournament $tournament, GameMatch $match): array
    {
        $this->assertResultsOpen($tournament, $match);

        if ($match->status === MatchStatus::CANCELLED) {
            throw new CustomErrorException(Message::MATCH_CANCELLED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($match->tie_id !== null) {
            return $this->playoffService->clearResult($match);
        }

        return ['match' => $this->matchRepository->setResult($match, null, null), 'tie' => null, 'advanced_to' => null];
    }

    public function deleteAll(Tournament $tournament): void
    {
        $this->assertInProgress($tournament, Message::MATCHES_DELETE_ONLY_IN_PROGRESS);

        if ($this->matchRepository->hasPlayedRegular($tournament->id)) {
            throw new CustomErrorException(Message::MATCHES_DELETE_HAS_PLAYED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->matchRepository->deleteRegular($tournament->id);
    }

    public function paginateByTournament(Tournament $tournament, ListQuery $query, array $filters): LengthAwarePaginator
    {
        return $this->matchRepository->paginateByTournament($tournament->id, $query, $filters);
    }

    public function schedule(Tournament $tournament): array
    {
        $matches = $this->matchRepository->regularMatches($tournament->id)->groupBy('group_id');
        $groups = [];

        foreach ($this->groupRepository->regularGroups($tournament->id) as $group) {
            $teams = $this->matchRepository->playingRegistrations($group->id);
            $matchdays = [];

            /** @var Collection<int, GameMatch> $groupMatches */
            $groupMatches = $matches->get($group->id) ?? collect();

            foreach ($groupMatches->groupBy('matchday') as $matchday => $played) {
                $playing = $played->flatMap(fn (GameMatch $match) => [$match->home_registration_id, $match->away_registration_id])->all();

                /** @var list<array{registration_id: int, team_name: string}> $resting */
                $resting = $teams
                    ->reject(fn ($registration) => in_array($registration->id, $playing, true))
                    ->map(fn ($registration) => ['registration_id' => $registration->id, 'team_name' => (string) ($registration->team->name ?? '')])
                    ->values()
                    ->all();

                $matchdays[] = [
                    'matchday' => (int) $matchday,
                    'round_number' => (int) $played->first()?->round_number,
                    'matches' => array_values($played->all()),
                    'resting' => $resting,
                ];
            }

            $groups[] = ['group_id' => $group->id, 'group_name' => $group->name, 'matchdays' => $matchdays];
        }

        return ['generated' => $matches->isNotEmpty(), 'groups' => $groups];
    }

    /**
     * Resultados abiertos: fase regular con el torneo en curso; liguilla con el torneo en liguilla.
     */
    private function assertResultsOpen(Tournament $tournament, GameMatch $match): void
    {
        $unprocessable = Response::HTTP_UNPROCESSABLE_ENTITY;

        if ($tournament->status === TournamentStatus::PLANNING) {
            throw new CustomErrorException(Message::TOURNAMENT_NOT_STARTED, $unprocessable);
        }

        if ($match->tie_id !== null) {
            if ($tournament->status === TournamentStatus::FINISHED) {
                throw new CustomErrorException(Message::MATCH_TOURNAMENT_FINISHED, $unprocessable);
            }

            if ($tournament->status !== TournamentStatus::PLAYOFFS) {
                throw new CustomErrorException(Message::PLAYOFFS_NOT_STARTED, $unprocessable);
            }

            return;
        }

        if ($tournament->status !== TournamentStatus::IN_PROGRESS) {
            throw new CustomErrorException(Message::REGULAR_STAGE_CLOSED, $unprocessable);
        }

        if ($match->phase !== MatchPhase::REGULAR_STAGE) {
            throw new CustomErrorException(Message::MATCH_RESULT_ONLY_REGULAR_STAGE, $unprocessable);
        }
    }

    /**
     * Si alguna de las dos inscripciones del partido está dada de baja.
     */
    private function hasWithdrawnTeam(GameMatch $match): bool
    {
        $match->loadMissing(['home', 'away']);

        return $match->home?->status === RegistrationStatus::WITHDRAWN || $match->away?->status === RegistrationStatus::WITHDRAWN;
    }

    private function assertInProgress(Tournament $tournament, string $message): void
    {
        if ($tournament->status !== TournamentStatus::IN_PROGRESS) {
            throw new CustomErrorException($message, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
