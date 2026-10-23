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
            ->with(['group', 'home.team', 'away.team'])
            ->orderBy('matchday')
            ->orderBy('group_id')
            ->orderBy('id');

        return $query->paginate($builder);
    }

    public function regularMatches(int $tournamentId): Collection
    {
        return $this->entity->newQuery()
            ->where('tournament_id', $tournamentId)
            ->where('phase', MatchPhase::REGULAR_STAGE)
            ->with(['group', 'home.team', 'away.team'])
            ->orderBy('matchday')
            ->orderBy('id')
            ->get();
    }
}
