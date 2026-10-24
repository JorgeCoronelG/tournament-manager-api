<?php

namespace App\Repositories;

use App\Contracts\Repositories\PlayoffRepositoryInterface;
use App\Models\BestPositionManualOrder;
use App\Models\GameMatch;
use App\Models\PlayoffTie;
use Illuminate\Database\Eloquent\Collection;

class PlayoffRepository implements PlayoffRepositoryInterface
{
    private const WITH = ['registrationA.team', 'registrationB.team', 'winner.team', 'matches.home.team', 'matches.away.team'];

    public function createTie(int $groupId, int $roundIndex, int $position, array $attributes): PlayoffTie
    {
        $tie = new PlayoffTie;
        $tie->forceFill($attributes + ['group_id' => $groupId, 'round_index' => $roundIndex, 'bracket_position' => $position]);
        $tie->saveOrFail();

        return $tie;
    }

    public function findTie(int $tieId): PlayoffTie
    {
        return PlayoffTie::query()->with(self::WITH)->findOrFail($tieId);
    }

    public function tiesOfGroups(array $groupIds): Collection
    {
        return PlayoffTie::query()
            ->whereIn('group_id', $groupIds)
            ->with(self::WITH)
            ->orderBy('group_id')
            ->orderBy('round_index')
            ->orderBy('bracket_position')
            ->get();
    }

    public function tieAt(int $groupId, int $roundIndex, int $position): ?PlayoffTie
    {
        return PlayoffTie::query()
            ->where('group_id', $groupId)
            ->where('round_index', $roundIndex)
            ->where('bracket_position', $position)
            ->with(self::WITH)
            ->first();
    }

    public function updateTie(PlayoffTie $tie, array $attributes): PlayoffTie
    {
        $tie->forceFill($attributes);
        $tie->saveOrFail();

        return $tie;
    }

    public function setMatchSides(int $matchId, ?int $homeRegistrationId, ?int $awayRegistrationId): void
    {
        GameMatch::query()->whereKey($matchId)->update([
            'home_registration_id' => $homeRegistrationId,
            'away_registration_id' => $awayRegistrationId,
        ]);
    }

    public function bestManualOrder(int $sourceId): array
    {
        return BestPositionManualOrder::query()->where('source_id', $sourceId)->pluck('sort_order', 'registration_id')
            ->map(fn (mixed $order) => is_numeric($order) ? (int) $order : 0)->all();
    }

    public function saveBestManualOrder(int $groupId, int $sourceId, array $registrationIds): void
    {
        BestPositionManualOrder::query()->where('source_id', $sourceId)->delete();

        foreach ($registrationIds as $index => $registrationId) {
            $row = new BestPositionManualOrder;
            $row->forceFill(['group_id' => $groupId, 'source_id' => $sourceId, 'registration_id' => $registrationId, 'sort_order' => $index + 1])->save();
        }
    }

    public function clearBestManualOrder(int $sourceId): void
    {
        BestPositionManualOrder::query()->where('source_id', $sourceId)->delete();
    }

    public function hasUndecidedTies(array $groupIds): bool
    {
        return PlayoffTie::query()->whereIn('group_id', $groupIds)->whereNull('winner_registration_id')->exists();
    }
}
