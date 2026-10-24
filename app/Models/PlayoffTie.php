<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cruce de una liguilla (uno o dos partidos). Los equipos son inscripciones y quedan nulos hasta definirse.
 */
class PlayoffTie extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'round_index' => 'integer',
        'bracket_position' => 'integer',
        'registration_a_id' => 'integer',
        'registration_b_id' => 'integer',
        'seed_a' => 'integer',
        'seed_b' => 'integer',
        'winner_registration_id' => 'integer',
    ];

    /**
     * @return BelongsTo<TournamentGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(TournamentGroup::class, 'group_id');
    }

    /**
     * @return BelongsTo<Registration, $this>
     */
    public function registrationA(): BelongsTo
    {
        return $this->belongsTo(Registration::class, 'registration_a_id');
    }

    /**
     * @return BelongsTo<Registration, $this>
     */
    public function registrationB(): BelongsTo
    {
        return $this->belongsTo(Registration::class, 'registration_b_id');
    }

    /**
     * @return BelongsTo<Registration, $this>
     */
    public function winner(): BelongsTo
    {
        return $this->belongsTo(Registration::class, 'winner_registration_id');
    }

    /**
     * Partidos del cruce, ida primero.
     *
     * @return HasMany<GameMatch, $this>
     */
    public function matches(): HasMany
    {
        return $this->hasMany(GameMatch::class, 'tie_id')->orderBy('leg')->orderBy('id');
    }
}
