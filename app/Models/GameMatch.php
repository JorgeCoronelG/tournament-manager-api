<?php

namespace App\Models;

use App\Core\Enum\MatchPhase;
use App\Core\Enum\MatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Partido (tabla `matches`; `Match` es palabra reservada en PHP). Los de fase regular llevan group_id, matchday y round_number.
 */
class GameMatch extends Model
{
    use SoftDeletes;

    protected $table = 'matches';

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'phase' => MatchPhase::class,
        'status' => MatchStatus::class,
        'match_datetime' => 'datetime',
        'scheduled_date' => 'date',
        'original_datetime' => 'datetime',
        'schedule_locked' => 'boolean',
        'leg' => 'integer',
        'round_index' => 'integer',
        'bracket_position' => 'integer',
        'home_registration_id' => 'integer',
        'away_registration_id' => 'integer',
    ];

    /**
     * @return BelongsTo<TournamentGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(TournamentGroup::class, 'group_id');
    }

    /**
     * @return BelongsTo<PlayoffTie, $this>
     */
    public function tie(): BelongsTo
    {
        return $this->belongsTo(PlayoffTie::class, 'tie_id');
    }

    /**
     * @return BelongsTo<Registration, $this>
     */
    public function home(): BelongsTo
    {
        return $this->belongsTo(Registration::class, 'home_registration_id');
    }

    /**
     * @return BelongsTo<Registration, $this>
     */
    public function away(): BelongsTo
    {
        return $this->belongsTo(Registration::class, 'away_registration_id');
    }
}
