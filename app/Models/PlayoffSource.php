<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Posiciones position_start a position_end de un grupo de origen que alimentan una liguilla.
 */
class PlayoffSource extends Model
{
    public const KIND_RANGE = 'range';

    public const KIND_BEST_OF_POSITION = 'best_of_position';

    /**
     * @var list<string>
     */
    protected $fillable = [];

    public function isBestOfPosition(): bool
    {
        return $this->kind === self::KIND_BEST_OF_POSITION;
    }

    /**
     * Equipos que aporta a la liguilla: el rango de posiciones o los mejores de una posición.
     */
    public function teamsCount(): int
    {
        return $this->isBestOfPosition() ? (int) $this->best_count : $this->position_end - $this->position_start + 1;
    }

    /**
     * @return BelongsTo<TournamentGroup, $this>
     */
    public function playoffGroup(): BelongsTo
    {
        return $this->belongsTo(TournamentGroup::class, 'playoff_group_id');
    }

    /**
     * @return BelongsTo<TournamentGroup, $this>
     */
    public function sourceGroup(): BelongsTo
    {
        return $this->belongsTo(TournamentGroup::class, 'source_group_id');
    }
}
