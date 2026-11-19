<?php

namespace App\Services;

use App\Contracts\Repositories\DashboardRepositoryInterface;
use App\Contracts\Services\DashboardServiceInterface;
use App\Contracts\Services\LeagueServiceInterface;
use App\Models\League;
use Carbon\CarbonInterface;

class DashboardService implements DashboardServiceInterface
{
    public function __construct(
        protected DashboardRepositoryInterface $dashboardRepository,
        protected LeagueServiceInterface $leagueService,
    ) {}

    public function summary(?int $leagueId = null): array
    {
        if ($leagueId !== null) {
            $this->leagueService->findById($leagueId);
        }

        return ['league_id' => $leagueId, 'leagues_active' => $this->dashboardRepository->activeLeagues()] + $this->figures($leagueId);
    }

    public function leagueSummary(League $league): array
    {
        return $this->figures($league->id);
    }

    /**
     * Las cinco cifras que cambian con la liga y la semana usada; un solo cálculo para los dos resúmenes.
     *
     * @return array{tournaments_active: int, teams_in_active_tournaments: int, players_in_active_tournaments: int, matches_this_week: int, referees_active: int, week: array{from: string, to: string}}
     */
    private function figures(?int $leagueId): array
    {
        $from = now()->startOfWeek(CarbonInterface::MONDAY);
        $to = now()->endOfWeek(CarbonInterface::SUNDAY);

        return [
            'tournaments_active' => $this->dashboardRepository->activeTournaments($leagueId),
            'teams_in_active_tournaments' => $this->dashboardRepository->teamsInActiveTournaments($leagueId),
            'players_in_active_tournaments' => $this->dashboardRepository->playersInActiveTournaments($leagueId),
            'matches_this_week' => $this->dashboardRepository->matchesBetween($from, $to, $leagueId),
            'referees_active' => $this->dashboardRepository->activeReferees($leagueId),
            'week' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ];
    }
}
