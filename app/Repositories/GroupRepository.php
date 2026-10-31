<?php

namespace App\Repositories;

use App\Contracts\Repositories\GroupRepositoryInterface;
use App\Core\BaseRepository;
use App\Core\Enum\RegistrationStatus;
use App\Core\Enum\TeamStatus;
use App\Core\Enum\TournamentGroupType;
use App\Models\PlayoffSource;
use App\Models\Registration;
use App\Models\TournamentGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @extends BaseRepository<TournamentGroup>
 */
class GroupRepository extends BaseRepository implements GroupRepositoryInterface
{
    public function __construct(TournamentGroup $entity)
    {
        parent::__construct($entity);
    }

    public function listByTournament(int $tournamentId): Collection
    {
        return $this->entity->newQuery()
            ->where('tournament_id', $tournamentId)
            ->withCount(['registrations as teams_count' => fn (Builder $builder) => $builder->where('status', RegistrationStatus::ACTIVE)])
            ->with(['sources.sourceGroup', 'tournament'])
            ->orderByRaw("CASE type WHEN 'regular_stage' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get();
    }

    public function unassignedRegistrations(int $tournamentId): Collection
    {
        return Registration::query()
            ->where('tournament_id', $tournamentId)
            ->where('status', RegistrationStatus::ACTIVE)
            ->whereNull('group_id')
            ->with('team.manager')
            ->orderBy('id')
            ->get();
    }

    public function registrationsOfGroup(int $groupId): Collection
    {
        return Registration::query()
            ->where('group_id', $groupId)
            ->whereIn('status', [RegistrationStatus::ACTIVE, RegistrationStatus::WITHDRAWN])
            ->with('team.manager')
            ->orderBy('id')
            ->get();
    }

    public function activeRegistrations(int $tournamentId): Collection
    {
        return Registration::query()
            ->where('tournament_id', $tournamentId)
            ->where('status', RegistrationStatus::ACTIVE)
            ->orderBy('id')
            ->get();
    }

    public function regularGroups(int $tournamentId): Collection
    {
        return $this->entity->newQuery()
            ->where('tournament_id', $tournamentId)
            ->where('type', TournamentGroupType::REGULAR_STAGE)
            ->orderBy('id')
            ->get();
    }

    public function createGroup(int $tournamentId, string $type, array $attributes): TournamentGroup
    {
        $group = $this->entity->newInstance();
        $group->forceFill(array_merge($attributes, ['tournament_id' => $tournamentId, 'type' => $type]));
        $group->saveOrFail();

        return $group;
    }

    public function updateGroup(TournamentGroup $group, array $attributes): TournamentGroup
    {
        $group->forceFill($attributes);
        $group->saveOrFail();

        return $group;
    }

    public function replaceSources(TournamentGroup $group, array $sources): void
    {
        PlayoffSource::query()->where('playoff_group_id', $group->id)->delete();

        foreach ($sources as $source) {
            $row = new PlayoffSource;
            $row->forceFill($source + ['playoff_group_id' => $group->id])->save();
        }
    }

    public function deleteGroup(TournamentGroup $group): void
    {
        PlayoffSource::query()->where('playoff_group_id', $group->id)->delete();
        $group->delete();
    }

    public function sourcesOfOtherPlayoffs(int $tournamentId, ?int $exceptGroupId): array
    {
        $sources = PlayoffSource::query()
            ->whereHas('playoffGroup', fn (Builder $group) => $group->where('tournament_id', $tournamentId))
            ->when($exceptGroupId !== null, fn (Builder $builder) => $builder->where('playoff_group_id', '!=', $exceptGroupId))
            ->get()
            ->map(fn (PlayoffSource $source) => [
                'kind' => (string) $source->kind,
                'source_group_id' => $source->source_group_id === null ? null : (int) $source->source_group_id,
                'position_start' => (int) $source->position_start,
                'position_end' => (int) $source->position_end,
                'best_count' => $source->best_count === null ? null : (int) $source->best_count,
            ])
            ->all();

        return array_values($sources);
    }

    public function hasMatches(int $groupId): bool
    {
        return DB::table('matches')->where('group_id', $groupId)->whereNull('deleted_at')->exists();
    }

    public function playingCount(int $groupId): int
    {
        return Registration::query()
            ->where('group_id', $groupId)
            ->where('status', RegistrationStatus::ACTIVE)
            ->whereHas('team', fn (Builder $team) => $team->where('status', '!=', TeamStatus::BANNED))
            ->count();
    }

    public function isUsedAsSource(int $groupId): bool
    {
        return PlayoffSource::query()
            ->where('source_group_id', $groupId)
            ->whereHas('playoffGroup')
            ->exists();
    }

    public function countRegistrations(int $groupId): int
    {
        return Registration::query()
            ->where('group_id', $groupId)
            ->where('status', RegistrationStatus::ACTIVE)
            ->count();
    }

    public function teamCounts(int $tournamentId): array
    {
        $counts = [];

        $groupIds = Registration::query()
            ->where('tournament_id', $tournamentId)
            ->where('status', RegistrationStatus::ACTIVE)
            ->whereNotNull('group_id')
            ->pluck('group_id');

        foreach ($groupIds as $groupId) {
            $key = (int) (is_numeric($groupId) ? $groupId : 0);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    public function setRegistrationGroup(int $registrationId, ?int $groupId): void
    {
        Registration::query()->whereKey($registrationId)->update(['group_id' => $groupId]);
    }

    public function assignUnassignedTo(int $tournamentId, int $groupId): void
    {
        Registration::query()
            ->where('tournament_id', $tournamentId)
            ->where('status', RegistrationStatus::ACTIVE)
            ->whereNull('group_id')
            ->update(['group_id' => $groupId]);
    }

    public function playoffGroups(int $tournamentId): Collection
    {
        return $this->entity->newQuery()
            ->where('tournament_id', $tournamentId)
            ->where('type', TournamentGroupType::PLAYOFF)
            ->with('sources')
            ->orderBy('id')
            ->get();
    }
}
