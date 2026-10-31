<?php

namespace App\Repositories;

use App\Contracts\Repositories\ScheduleRepositoryInterface;
use App\Core\Enum\FieldStatus;
use App\Core\Enum\LeagueRefereeStatus;
use App\Core\Enum\MatchPhase;
use App\Core\Enum\MatchStatus;
use App\Core\Enum\RosterStatus;
use App\Models\Field;
use App\Models\GameMatch;
use App\Models\LeagueReferee;
use App\Models\Roster;
use App\Models\Tournament;
use App\Models\TournamentScheduleBlock;
use App\Models\TournamentScheduleWindow;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ScheduleRepository implements ScheduleRepositoryInterface
{
    public function windows(int $tournamentId): Collection
    {
        return TournamentScheduleWindow::query()
            ->where('tournament_id', $tournamentId)
            ->with('field')
            ->orderBy('weekday')
            ->orderBy('field_id')
            ->orderBy('start_time')
            ->get();
    }

    public function blocks(int $tournamentId): Collection
    {
        return TournamentScheduleBlock::query()
            ->where('tournament_id', $tournamentId)
            ->with('field')
            ->orderBy('date')
            ->orderBy('start_time')
            ->orderBy('id')
            ->get();
    }

    public function replace(Tournament $tournament, ?string $startDate, array $windows, array $blocks): void
    {
        DB::transaction(function () use ($tournament, $startDate, $windows, $blocks) {
            $now = now();

            $tournament->forceFill(['schedule_start_date' => $startDate])->save();

            TournamentScheduleWindow::query()->where('tournament_id', $tournament->id)->delete();
            TournamentScheduleBlock::query()->where('tournament_id', $tournament->id)->delete();

            $stamp = ['tournament_id' => $tournament->id, 'created_at' => $now, 'updated_at' => $now];

            TournamentScheduleWindow::query()->insert(array_map(fn (array $window) => $window + $stamp, $windows));
            TournamentScheduleBlock::query()->insert(array_map(fn (array $block) => $block + $stamp, $blocks));
        });
    }

    public function countLeagueFields(int $leagueId, array $fieldIds, bool $onlyActive): int
    {
        return Field::query()
            ->where('league_id', $leagueId)
            ->whereIn('id', $fieldIds)
            ->when($onlyActive, fn ($query) => $query->where('status', FieldStatus::ACTIVE))
            ->count();
    }

    public function timedMatches(int $tournamentId): array
    {
        $rows = [];

        $matches = GameMatch::query()
            ->where('tournament_id', $tournamentId)
            ->where('phase', MatchPhase::REGULAR_STAGE)
            ->whereNotIn('status', [MatchStatus::PLAYED, MatchStatus::CANCELLED])
            ->whereNotNull('match_datetime')
            ->get(['match_datetime', 'field_id', 'schedule_locked']);

        foreach ($matches as $match) {
            $at = $match->match_datetime;

            if ($at !== null) {
                $rows[] = ['date' => $at->format('Y-m-d'), 'start' => (int) $at->format('G') * 60 + (int) $at->format('i'), 'field_id' => $match->field_id, 'locked' => (bool) $match->schedule_locked];
            }
        }

        return $rows;
    }

    public function matchesPerMatchday(int $tournamentId): array
    {
        return GameMatch::query()
            ->where('tournament_id', $tournamentId)
            ->where('phase', MatchPhase::REGULAR_STAGE)
            ->whereNotNull('matchday')
            ->groupBy('matchday')
            ->orderBy('matchday')
            ->selectRaw('matchday, COUNT(*) as total')
            ->pluck('total', 'matchday')
            ->map(fn ($total) => is_numeric($total) ? (int) $total : 0)
            ->all();
    }

    public function activeReferees(int $leagueId): Collection
    {
        return LeagueReferee::query()
            ->where('league_id', $leagueId)
            ->where('status', LeagueRefereeStatus::ACTIVE)
            ->with('user')
            ->get()
            ->sortBy(fn (LeagueReferee $referee) => mb_strtolower(trim(($referee->user->first_name ?? '').' '.($referee->user->last_name ?? ''))))
            ->values();
    }

    public function isActiveReferee(int $leagueId, int $userId): bool
    {
        return LeagueReferee::query()
            ->where('league_id', $leagueId)
            ->where('user_id', $userId)
            ->where('status', LeagueRefereeStatus::ACTIVE)
            ->exists();
    }

    public function playsInRegistrations(int $userId, array $registrationIds): bool
    {
        return Roster::query()
            ->whereIn('registration_id', $registrationIds)
            ->where('status', RosterStatus::ACTIVE)
            ->whereHas('player', fn ($query) => $query->where('user_id', $userId))
            ->exists();
    }

    public function activeFields(int $leagueId): Collection
    {
        return Field::query()->where('league_id', $leagueId)->where('status', FieldStatus::ACTIVE)->orderBy('name')->orderBy('id')->get();
    }
}
