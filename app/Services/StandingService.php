<?php

namespace App\Services;

use App\Contracts\Repositories\GroupRepositoryInterface;
use App\Contracts\Repositories\MatchRepositoryInterface;
use App\Contracts\Services\StandingServiceInterface;
use App\Core\Enum\Message;
use App\Core\Enum\RegistrationStatus;
use App\Core\Enum\Tiebreaker;
use App\Core\Enum\TournamentStatus;
use App\Exceptions\CustomErrorException;
use App\Helpers\Standings;
use App\Models\GameMatch;
use App\Models\Registration;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class StandingService implements StandingServiceInterface
{
    public function __construct(
        protected MatchRepositoryInterface $matchRepository,
        protected GroupRepositoryInterface $groupRepository,
    ) {}

    public function standings(Tournament $tournament, ?int $groupId): array
    {
        if ($tournament->status === TournamentStatus::PLANNING) {
            throw new CustomErrorException(Message::TOURNAMENT_NOT_STARTED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $groups = $this->groupRepository->regularGroups($tournament->id);

        if ($groupId !== null) {
            $groups = $groups->where('id', $groupId)->values();

            if ($groups->isEmpty()) {
                throw new CustomErrorException(Message::STANDINGS_GROUP_INVALID, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        return array_values($groups->map(fn (TournamentGroup $group) => $this->groupStandings($tournament, $group, true))->all());
    }

    public function saveManualOrder(Tournament $tournament, int $groupId, array $registrationIds): void
    {
        $group = $this->assertManualOrderAllowed($tournament, $groupId);

        // Los empates se calculan sin el orden manual guardado: se puede volver a ordenar el mismo conjunto.
        $ties = $this->groupStandings($tournament, $group, false)['ties'];
        $wanted = $registrationIds;
        sort($wanted);

        foreach ($ties as $tie) {
            $sorted = $tie;
            sort($sorted);

            if ($sorted === $wanted) {
                $this->matchRepository->saveManualOrder($tournament->id, $group->id, $registrationIds);

                return;
            }
        }

        throw ValidationException::withMessages(['registration_ids' => Message::STANDINGS_NOT_TIED]);
    }

    public function clearManualOrder(Tournament $tournament, int $groupId): void
    {
        $group = $this->assertManualOrderAllowed($tournament, $groupId);

        $this->matchRepository->clearManualOrder($group->id);
    }

    private function assertManualOrderAllowed(Tournament $tournament, int $groupId): TournamentGroup
    {
        if ($tournament->status !== TournamentStatus::IN_PROGRESS) {
            throw new CustomErrorException(Message::STANDINGS_MANUAL_ORDER_ONLY_IN_PROGRESS, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $group = $this->groupRepository->regularGroups($tournament->id)->firstWhere('id', $groupId);

        if (! $group instanceof TournamentGroup) {
            throw new CustomErrorException(Message::STANDINGS_GROUP_INVALID, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $group;
    }

    /**
     * @return array{group_id: int, group_name: string, criteria: list<string>, rows: list<array<string, mixed>>, ties: list<list<int>>}
     */
    private function groupStandings(Tournament $tournament, TournamentGroup $group, bool $useManualOrder): array
    {
        $registrations = $this->matchRepository->registrationsOfGroup($group->id)->keyBy('id');
        $matches = $this->matchRepository->playedOfGroup($group->id)->map(fn (GameMatch $match) => [
            'home' => $match->home_registration_id,
            'away' => $match->away_registration_id,
            'home_score' => (int) $match->home_score,
            'away_score' => (int) $match->away_score,
        ])->values()->all();
        /** @var list<array{home: int, away: int, home_score: int, away_score: int}> $matches */

        /** @var list<string> $criteria */
        $criteria = array_values(array_filter((array) $tournament->tiebreakers, 'is_string'));

        $table = Standings::build(
            array_values($registrations->keys()->map(fn ($id) => (int) $id)->all()),
            $matches,
            $criteria === [] ? Tiebreaker::defaults() : $criteria,
            $useManualOrder ? $this->matchRepository->manualOrder($group->id) : []
        );

        $rows = array_map(function (array $row) use ($registrations) {
            /** @var Registration $registration */
            $registration = $registrations[$row['registration_id']];

            return [
                'position' => $row['position'],
                'registration_id' => $registration->id,
                'team_id' => $registration->team_id,
                'team_name' => $registration->team->name ?? '',
                'logo_url' => $registration->team?->logoUrl(),
                'withdrawn' => $registration->status === RegistrationStatus::WITHDRAWN,
            ] + array_diff_key($row, ['position' => 1, 'registration_id' => 1]);
        }, $table['rows']);

        return [
            'group_id' => $group->id,
            'group_name' => $group->name,
            'criteria' => $criteria === [] ? Tiebreaker::defaults() : $criteria,
            'rows' => $rows,
            'ties' => $table['ties'],
        ];
    }
}
