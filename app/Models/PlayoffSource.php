<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Posiciones position_start a position_end de un grupo de origen que alimentan una liguilla.
 */
class PlayoffSource extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [];

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
