<?php

namespace App\Services;

use App\Contracts\Repositories\MatchRepositoryInterface;
use App\Contracts\Repositories\RegistrationRepositoryInterface;
use App\Contracts\Services\PlayoffServiceInterface;
use App\Contracts\Services\StandingServiceInterface;
use App\Contracts\Services\TournamentSummaryServiceInterface;
use App\Core\Enum\Message;
use App\Core\Enum\TournamentStatus;
use App\Exceptions\CustomErrorException;
use App\Models\Tournament;
use Symfony\Component\HttpFoundation\Response;

class TournamentSummaryService implements TournamentSummaryServiceInterface
{
    public function __construct(
        protected RegistrationRepositoryInterface $registrationRepository,
        protected MatchRepositoryInterface $matchRepository,
        protected PlayoffServiceInterface $playoffService,
        protected StandingServiceInterface $standingService,
    ) {}

    public function summary(Tournament $tournament): array
    {
        if ($tournament->status !== TournamentStatus::FINISHED) {
            throw new CustomErrorException(Message::TOURNAMENT_NOT_FINISHED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $playoffs = $this->playoffService->results($tournament);
        $totals = $this->matchRepository->playedTotals($tournament->id);

        return [
            'teams_count' => $this->registrationRepository->countActiveByTournament($tournament->id)
                - $this->registrationRepository->countBannedTeamsByTournament($tournament->id),
            'playoffs_count' => count($playoffs),
            'matches_played' => $totals['matches'],
            'goals_scored' => $totals['goals'],
            'playoffs' => $playoffs,
            'champion' => $playoffs === [] ? $this->championByStandings($tournament) : null,
        ];
    }

    /**
     * @return array{source: string, teams: list<array<string, mixed>>}|null
     */
    private function championByStandings(Tournament $tournament): ?array
    {
        $teams = [];

        foreach ($this->standingService->standings($tournament, null) as $group) {
            $leaders = array_values(array_filter($group['rows'], fn (array $row): bool => $row['position'] === 1));

            // Sin partidos jugados o con un empate sin resolver en el primer lugar no hay campeón definido.
            if (count($leaders) !== 1 || $leaders[0]['played'] === 0) {
                return null;
            }

            $teams[] = [
                'id' => $leaders[0]['team_id'],
                'name' => $leaders[0]['team_name'],
                'logo_url' => $leaders[0]['logo_url'],
                'group_id' => $group['group_id'],
                'group_name' => $group['group_name'],
            ];
        }

        return $teams === [] ? null : ['source' => 'standings', 'teams' => $teams];
    }
}
