<?php

namespace App\Repositories;

use App\Contracts\Repositories\ScheduleRepositoryInterface;
use App\Core\Enum\FieldStatus;
use App\Core\Enum\MatchPhase;
use App\Models\Field;
use App\Models\GameMatch;
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

    public function hasTimedMatches(int $tournamentId): bool
    {
        return GameMatch::query()
            ->where('tournament_id', $tournamentId)
            ->where('phase', MatchPhase::REGULAR_STAGE)
            ->whereNotNull('match_datetime')
            ->exists();
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
}
