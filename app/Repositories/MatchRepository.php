<?php

namespace App\Repositories;

use App\Contracts\Repositories\MatchRepositoryInterface;
use App\Core\BaseRepository;
use App\Core\Classes\ListQuery;
use App\Core\Enum\MatchPhase;
use App\Core\Enum\MatchStatus;
use App\Core\Enum\RegistrationStatus;
use App\Core\Enum\TeamStatus;
use App\Models\GameMatch;
use App\Models\Registration;
use App\Models\StandingManualOrder;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepository<GameMatch>
 */
class MatchRepository extends BaseRepository implements MatchRepositoryInterface
{
    public function __construct(GameMatch $entity)
    {
        parent::__construct($entity);
    }

    public function playingRegistrations(int $groupId): Collection
    {
        return Registration::query()
            ->where('group_id', $groupId)
            ->where('status', RegistrationStatus::ACTIVE)
            ->whereHas('team', fn (Builder $team) => $team->where('status', '!=', TeamStatus::BANNED))
            ->with('team')
            ->orderBy('id')
            ->get();
    }

    public function hasPlayedRegular(int $tournamentId): bool
    {
        return $this->entity->newQuery()
            ->where('tournament_id', $tournamentId)
            ->where('phase', MatchPhase::REGULAR_STAGE)
            ->where('status', MatchStatus::PLAYED)
            ->exists();
    }

    public function deleteRegular(int $tournamentId): void
    {
        $this->entity->newQuery()
            ->where('tournament_id', $tournamentId)
            ->where('phase', MatchPhase::REGULAR_STAGE)
            ->delete();
    }

    public function insertMany(array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            $this->entity->newQuery()->insert($chunk);
        }
    }

    public function paginateByTournament(int $tournamentId, ListQuery $query, array $filters): LengthAwarePaginator
    {
        $builder = $this->entity->newQuery()
            ->where('tournament_id', $tournamentId)
            ->when(array_key_exists('group_id', $filters), fn (Builder $b) => $b->where('group_id', $filters['group_id'] ?? null))
            ->when(array_key_exists('matchday', $filters), fn (Builder $b) => $b->where('matchday', $filters['matchday'] ?? null))
            ->when(array_key_exists('status', $filters), fn (Builder $b) => $b->where('status', $filters['status'] ?? null))
            ->when(array_key_exists('round_number', $filters), fn (Builder $b) => $b->where('round_number', $filters['round_number'] ?? null))
            ->when(array_key_exists('phase', $filters), fn (Builder $b) => $b->where('phase', $filters['phase'] ?? null))
            ->with(['group', 'field', 'referee', 'home.team', 'away.team'])
            ->orderBy('matchday')
            ->orderBy('group_id')
            ->orderBy('id');

        return $query->paginate($builder);
    }

    public function setResult(GameMatch $match, ?int $homeScore, ?int $awayScore, ?int $homePenalties = null, ?int $awayPenalties = null): GameMatch
    {
        $match->forceFill([
            'home_score' => $homeScore,
            'away_score' => $awayScore,
            'home_penalties' => $homePenalties,
            'away_penalties' => $awayPenalties,
            'status' => $homeScore === null ? MatchStatus::SCHEDULED : MatchStatus::PLAYED,
        ]);
        $match->saveOrFail();

        return $match;
    }

    public function lastRegularDate(int $tournamentId): ?string
    {
        $last = $this->entity->newQuery()
            ->where('tournament_id', $tournamentId)
            ->where('phase', MatchPhase::REGULAR_STAGE)
            ->whereNotNull('match_datetime')
            ->max('match_datetime');

        return is_string($last) ? substr($last, 0, 10) : null;
    }

    public function playedOfGroup(int $groupId): Collection
    {
        return $this->entity->newQuery()
            ->where('group_id', $groupId)
            ->where('phase', MatchPhase::REGULAR_STAGE)
            ->where('status', MatchStatus::PLAYED)
            ->get();
    }

    public function activeRegistrationsOfGroup(int $groupId): Collection
    {
        return Registration::query()
            ->where('group_id', $groupId)
            ->where('status', RegistrationStatus::ACTIVE)
            ->with('team')
            ->orderBy('id')
            ->get();
    }

    public function manualOrder(int $groupId): array
    {
        return StandingManualOrder::query()->where('group_id', $groupId)->pluck('position', 'registration_id')->map(fn (mixed $position) => is_numeric($position) ? (int) $position : 0)->all();
    }

    public function saveManualOrder(int $tournamentId, int $groupId, array $registrationIds): void
    {
        StandingManualOrder::query()->where('group_id', $groupId)->whereIn('registration_id', $registrationIds)->delete();

        foreach ($registrationIds as $index => $registrationId) {
            $row = new StandingManualOrder;
            $row->forceFill([
                'tournament_id' => $tournamentId,
                'group_id' => $groupId,
                'registration_id' => $registrationId,
                'position' => $index + 1,
            ])->save();
        }
    }

    public function clearManualOrder(int $groupId): void
    {
        StandingManualOrder::query()->where('group_id', $groupId)->delete();
    }

    public function regularMatches(int $tournamentId): Collection
    {
        return $this->entity->newQuery()
            ->where('tournament_id', $tournamentId)
            ->where('phase', MatchPhase::REGULAR_STAGE)
            ->with(['group', 'field', 'referee', 'home.team', 'away.team'])
            ->orderBy('matchday')
            ->orderBy('id')
            ->get();
    }

    public function assignSchedule(array $assignments): void
    {
        $now = now();

        foreach ($assignments as $matchId => [$datetime, $fieldId, $reset]) {
            $columns = ['match_datetime' => $datetime, 'field_id' => $fieldId, 'updated_at' => $now];

            if ($reset) {
                $columns += ['referee_user_id' => null, 'original_match_datetime' => null];
            }

            $this->entity->newQuery()->whereKey($matchId)->update($columns);
        }
    }

    public function scheduledInLeague(int $leagueId, CarbonInterface $from, CarbonInterface $to, array $excludeIds = []): Collection
    {
        return $this->entity->newQuery()
            ->whereHas('tournament', fn ($query) => $query->where('league_id', $leagueId))
            ->whereNotNull('match_datetime')
            ->where('status', '!=', MatchStatus::CANCELLED)
            ->whereBetween('match_datetime', [$from, $to])
            ->when($excludeIds !== [], fn ($query) => $query->whereNotIn('id', $excludeIds))
            ->with(['tournament', 'home.team', 'away.team', 'field'])
            ->orderBy('match_datetime')
            ->orderBy('id')
            ->get();
    }

    public function saveSchedule(GameMatch $match, array $attributes): GameMatch
    {
        $match->forceFill($attributes)->save();

        return $match;
    }

    public function playoffMatches(int $tournamentId): Collection
    {
        return $this->entity->newQuery()
            ->where('tournament_id', $tournamentId)
            ->whereNotNull('tie_id')
            ->with(['group', 'field', 'referee', 'home.team', 'away.team'])
            ->orderBy('scheduled_date')
            ->orderBy('round_index')
            ->orderBy('bracket_position')
            ->orderBy('leg')
            ->orderBy('id')
            ->get();
    }

    public function agenda(int $leagueId, string $dateFrom, string $dateTo, array $filters): Collection
    {
        $tournamentId = $filters['tournament_id'] ?? null;
        $fieldId = $filters['field_id'] ?? null;
        $refereeId = $filters['referee_user_id'] ?? null;

        return $this->entity->newQuery()
            ->whereHas('tournament', fn ($query) => $query->where('league_id', $leagueId))
            ->whereNotNull('match_datetime')
            ->whereBetween('match_datetime', [$dateFrom.' 00:00:00', $dateTo.' 23:59:59'])
            ->when($tournamentId !== null, fn ($query) => $query->where('tournament_id', $tournamentId))
            ->when($fieldId !== null, fn ($query) => $query->where('field_id', $fieldId))
            ->when($refereeId !== null, fn ($query) => $query->where('referee_user_id', $refereeId))
            ->when(! empty($filters['unassigned_referee']), fn ($query) => $query->whereNull('referee_user_id'))
            ->with(['tournament', 'group', 'field', 'referee', 'home.team', 'away.team'])
            ->orderBy('match_datetime')
            ->orderBy('field_id')
            ->orderBy('id')
            ->get();
    }

    public function countUnscheduled(int $leagueId, ?int $tournamentId): int
    {
        return $this->entity->newQuery()
            ->whereHas('tournament', fn ($query) => $query->where('league_id', $leagueId))
            ->whereNull('match_datetime')
            ->whereNotIn('status', [MatchStatus::PLAYED, MatchStatus::CANCELLED])
            ->when($tournamentId !== null, fn ($query) => $query->where('tournament_id', $tournamentId))
            ->count();
    }
}
