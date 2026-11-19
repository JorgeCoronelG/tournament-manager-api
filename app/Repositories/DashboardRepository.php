<?php

namespace App\Repositories;

use App\Contracts\Repositories\DashboardRepositoryInterface;
use App\Core\Enum\LeagueRefereeStatus;
use App\Core\Enum\LeagueStatus;
use App\Core\Enum\MatchStatus;
use App\Core\Enum\RegistrationStatus;
use App\Core\Enum\RosterStatus;
use App\Core\Enum\TeamStatus;
use App\Core\Enum\TournamentStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class DashboardRepository implements DashboardRepositoryInterface
{
    public function activeLeagues(): int
    {
        return DB::table('leagues')->where('status', LeagueStatus::ACTIVE->value)->whereNull('leagues.deleted_at')->count();
    }

    public function activeTournaments(?int $leagueId = null): int
    {
        return $this->activeTournamentsQuery($leagueId)->count();
    }

    public function teamsInActiveTournaments(?int $leagueId = null): int
    {
        return $this->activeTournamentsQuery($leagueId)
            ->join('registrations', 'registrations.tournament_id', '=', 'tournaments.id')
            ->join('teams', 'teams.id', '=', 'registrations.team_id')
            ->where('registrations.status', RegistrationStatus::ACTIVE->value)
            ->whereNull('registrations.deleted_at')
            ->whereNull('teams.deleted_at')
            ->where('teams.status', '!=', TeamStatus::BANNED->value)
            ->distinct()
            ->count('registrations.team_id');
    }

    public function playersInActiveTournaments(?int $leagueId = null): int
    {
        return $this->activeTournamentsQuery($leagueId)
            ->join('rosters', 'rosters.tournament_id', '=', 'tournaments.id')
            ->join('registrations', 'registrations.id', '=', 'rosters.registration_id')
            ->where('rosters.status', RosterStatus::ACTIVE->value)
            ->whereNull('registrations.deleted_at')
            ->distinct()
            ->count('rosters.player_id');
    }

    public function matchesBetween(CarbonInterface $from, CarbonInterface $to, ?int $leagueId = null): int
    {
        return $this->inLeagues(DB::table('matches')
            ->join('tournaments', 'tournaments.id', '=', 'matches.tournament_id')
            ->join('leagues', 'leagues.id', '=', 'tournaments.league_id'), $leagueId)
            ->whereNull('tournaments.deleted_at')
            ->whereNull('matches.deleted_at')
            ->whereIn('matches.status', [MatchStatus::SCHEDULED->value, MatchStatus::PLAYED->value])
            ->whereBetween('matches.match_datetime', [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')])
            ->count();
    }

    public function activeReferees(?int $leagueId = null): int
    {
        return $this->inLeagues(DB::table('league_referees')
            ->join('leagues', 'leagues.id', '=', 'league_referees.league_id'), $leagueId)
            ->join('users', 'users.id', '=', 'league_referees.user_id')
            ->where('league_referees.status', LeagueRefereeStatus::ACTIVE->value)
            ->whereNull('league_referees.deleted_at')
            ->whereNull('users.deleted_at')
            ->distinct()
            ->count('league_referees.user_id');
    }

    /**
     * Torneos en curso o en liguilla de las ligas activas, o de la liga dada.
     */
    private function activeTournamentsQuery(?int $leagueId): Builder
    {
        return $this->inLeagues(DB::table('tournaments')->join('leagues', 'leagues.id', '=', 'tournaments.league_id'), $leagueId)
            ->whereNull('tournaments.deleted_at')
            ->whereIn('tournaments.status', [TournamentStatus::IN_PROGRESS->value, TournamentStatus::PLAYOFFS->value]);
    }

    /**
     * Limita una consulta (que ya une `leagues`) a las ligas no eliminadas: las activas, o solo la liga dada aunque
     * esté inactiva.
     */
    private function inLeagues(Builder $query, ?int $leagueId): Builder
    {
        return $query
            ->whereNull('leagues.deleted_at')
            ->when(
                $leagueId === null,
                fn (Builder $builder) => $builder->where('leagues.status', LeagueStatus::ACTIVE->value),
                fn (Builder $builder) => $builder->where('leagues.id', $leagueId),
            );
    }
}
