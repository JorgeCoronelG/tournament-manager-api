<?php

namespace App\Repositories;

use App\Contracts\Repositories\FeedRepositoryInterface;
use App\Core\Enum\LeagueStatus;
use App\Core\Enum\MatchPhase;
use App\Core\Enum\MatchStatus;
use App\Core\Enum\RosterStatus;
use App\Core\Enum\TournamentStatus;
use App\Models\GameMatch;
use App\Models\League;
use App\Models\Registration;
use App\Models\Tournament;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\DB;

class FeedRepository implements FeedRepositoryInterface
{
    public function relatedLeagueIds(User $user): array
    {
        $asPlayer = DB::table('rosters')
            ->join('players', 'players.id', '=', 'rosters.player_id')
            ->join('tournaments', 'tournaments.id', '=', 'rosters.tournament_id')
            ->where('players.user_id', $user->id)
            ->where('rosters.status', RosterStatus::ACTIVE->value)
            ->pluck('tournaments.league_id');
        $asManager = DB::table('teams')->where('manager_user_id', $user->id)->whereNull('deleted_at')->pluck('league_id');
        $asReferee = DB::table('league_referees')->where('user_id', $user->id)->where('status', 'active')
            ->whereNull('deleted_at')->pluck('league_id');
        $asAdmin = DB::table('leagues')->where('admin_user_id', $user->id)->pluck('id');

        $ids = $asPlayer->merge($asManager)->merge($asReferee)->merge($asAdmin)->map(fn ($id) => (int) $id)->unique()->values()->all();

        return $ids === [] ? [] : array_values(array_map('intval', League::query()
            ->whereIn('id', $ids)
            ->where('status', LeagueStatus::ACTIVE)
            ->pluck('id')
            ->all()));
    }

    public function activeLeagueIds(): array
    {
        return array_values(array_map('intval', League::query()->where('status', LeagueStatus::ACTIVE)->pluck('id')->all()));
    }

    public function visibleTournaments(array $leagueIds, CarbonInterface $finishedSince): Collection
    {
        return Tournament::query()
            ->whereIn('league_id', $leagueIds)
            ->where(function (Builder $query) use ($finishedSince): void {
                $query->whereIn('status', [TournamentStatus::IN_PROGRESS, TournamentStatus::PLAYOFFS])
                    ->orWhere(fn (Builder $finished) => $finished
                        ->where('status', TournamentStatus::FINISHED)
                        ->whereHas('matches', fn (Builder $match) => $match->where('match_datetime', '>=', $finishedSince)));
            })
            ->with('league')
            ->orderBy('league_id')
            ->orderBy('name')
            ->get();
    }

    public function myRegistrationIds(User $user, array $tournamentIds): array
    {
        if ($tournamentIds === []) {
            return [];
        }

        $asPlayer = DB::table('rosters')
            ->join('players', 'players.id', '=', 'rosters.player_id')
            ->where('players.user_id', $user->id)
            ->where('rosters.status', RosterStatus::ACTIVE->value)
            ->whereIn('rosters.tournament_id', $tournamentIds)
            ->pluck('rosters.registration_id');
        $asManager = Registration::query()
            ->whereIn('tournament_id', $tournamentIds)
            ->whereHas('team', fn (Builder $team) => $team->where('manager_user_id', $user->id))
            ->pluck('id');

        return $asPlayer->merge($asManager)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
    }

    public function matches(
        array $tournamentIds,
        ?array $registrationIds,
        ?CarbonInterface $from,
        ?CarbonInterface $to,
        array $statuses,
        bool $newestFirst,
        int $offset = 0,
        ?int $limit = null,
    ): Collection {
        $query = GameMatch::query()
            ->whereIn('tournament_id', $tournamentIds)
            ->whereNotNull('home_registration_id')
            ->whereNotNull('away_registration_id')
            ->whereNotNull('match_datetime')
            ->whereIn('status', $statuses)
            ->when($registrationIds !== null, fn (Builder $q) => $q->where(fn (Builder $side) => $side
                ->whereIn('home_registration_id', $registrationIds ?? [])
                ->orWhereIn('away_registration_id', $registrationIds ?? [])))
            ->when($from !== null, fn (Builder $q) => $q->where('match_datetime', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->where('match_datetime', '<=', $to))
            ->orderBy('match_datetime', $newestFirst ? 'desc' : 'asc')
            ->orderBy('id', $newestFirst ? 'desc' : 'asc')
            ->with(['tournament.league', 'field', 'home.team', 'away.team']);

        if ($offset > 0) {
            $query->skip($offset);
        }

        if ($limit !== null) {
            $query->take($limit);
        }

        return $query->get();
    }

    public function eventsOf(GameMatch $match): BaseCollection
    {
        return DB::table('match_events as e')
            ->where('e.match_id', $match->id)
            ->where('e.is_voided', false)
            ->leftJoin('players as p', 'p.id', '=', 'e.player_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.user_id')
            ->leftJoin('players as rp', 'rp.id', '=', 'e.related_player_id')
            ->leftJoin('users as ru', 'ru.id', '=', 'rp.user_id')
            ->orderByRaw('e.minute is null, e.minute')
            ->orderBy('e.id')
            ->get([
                'e.id', 'e.type', 'e.minute', 'e.registration_id', 'e.player_id', 'e.related_player_id',
                'u.first_name', 'u.last_name', 'ru.first_name as related_first_name', 'ru.last_name as related_last_name',
            ])
            ->map(function (object $event) use ($match): object {
                $event->jersey_number = $this->jersey((int) $event->registration_id, $event->player_id, $match->tournament_id);
                $event->related_jersey_number = $this->jersey((int) $event->registration_id, $event->related_player_id, $match->tournament_id);

                return $event;
            });
    }

    public function lineupsOf(GameMatch $match): BaseCollection
    {
        return DB::table('scans as s')
            ->where('s.match_id', $match->id)
            ->where('s.result', 'valid')
            ->whereNotNull('s.player_id')
            ->whereNotNull('s.registration_id')
            ->join('players as p', 'p.id', '=', 's.player_id')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->leftJoin('rosters as r', fn ($join) => $join
                ->on('r.player_id', '=', 's.player_id')
                ->on('r.registration_id', '=', 's.registration_id')
                ->whereIn('r.status', [RosterStatus::ACTIVE->value, RosterStatus::REMOVED->value]))
            ->groupBy('s.registration_id', 's.player_id', 'u.first_name', 'u.last_name')
            ->orderBy('s.registration_id')
            ->get([
                's.registration_id', 's.player_id', 'u.first_name', 'u.last_name',
                DB::raw('MAX(r.jersey_number) as jersey_number'),
            ]);
    }

    public function playedRegularMatches(int $tournamentId): Collection
    {
        return GameMatch::query()
            ->where('tournament_id', $tournamentId)
            ->where('phase', MatchPhase::REGULAR_STAGE)
            ->where('status', MatchStatus::PLAYED)
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->orderBy('match_datetime')
            ->orderBy('id')
            ->get();
    }

    public function playerTotals(int $tournamentId): BaseCollection
    {
        return DB::table('match_events as e')
            ->join('matches as m', 'm.id', '=', 'e.match_id')
            ->join('players as p', 'p.id', '=', 'e.player_id')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->join('registrations as reg', 'reg.id', '=', 'e.registration_id')
            ->join('teams as t', 't.id', '=', 'reg.team_id')
            ->where('m.tournament_id', $tournamentId)
            ->where('m.status', MatchStatus::PLAYED->value)
            ->whereNull('m.deleted_at')
            ->where('e.is_voided', false)
            ->whereIn('e.type', ['goal', 'yellow_card', 'red_card'])
            ->groupBy('e.player_id', 'e.registration_id', 'u.first_name', 'u.last_name', 't.name')
            ->get([
                'e.player_id', 'e.registration_id', 'u.first_name', 'u.last_name', 't.name as team_name',
                DB::raw("SUM(CASE WHEN e.type = 'goal' THEN 1 ELSE 0 END) as goals"),
                DB::raw("SUM(CASE WHEN e.type = 'yellow_card' THEN 1 ELSE 0 END) as yellow_cards"),
                DB::raw("SUM(CASE WHEN e.type = 'red_card' THEN 1 ELSE 0 END) as red_cards"),
            ]);
    }

    /** @var array<string, int|null> */
    private array $jerseys = [];

    private function jersey(int $registrationId, mixed $playerId, int $tournamentId): ?int
    {
        if ($playerId === null) {
            return null;
        }

        $key = $registrationId.'-'.$playerId;

        if (! array_key_exists($key, $this->jerseys)) {
            $jersey = DB::table('rosters')
                ->where('tournament_id', $tournamentId)
                ->where('registration_id', $registrationId)
                ->where('player_id', $playerId)
                ->whereIn('status', [RosterStatus::ACTIVE->value, RosterStatus::REMOVED->value])
                ->orderByDesc('id')
                ->value('jersey_number');
            $this->jerseys[$key] = $jersey === null ? null : (int) $jersey;
        }

        return $this->jerseys[$key];
    }
}
