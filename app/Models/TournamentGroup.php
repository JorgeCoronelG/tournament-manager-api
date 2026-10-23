<?php

namespace App\Models;

use App\Core\Enum\TournamentGroupType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Grupo de un torneo: de fase regular o de liguilla (type playoff, con sus equipos en playoff_sources).
 */
class TournamentGroup extends Model
{
    use SoftDeletes;

    /** Nombre del grupo que se crea con cada torneo. */
    public const DEFAULT_NAME = 'Fase regular';

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'type' => TournamentGroupType::class,
        'start_date' => 'date',
    ];

    /**
     * @return BelongsTo<Tournament, $this>
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    /**
     * Orígenes de los equipos de esta liguilla.
     *
     * @return HasMany<PlayoffSource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(PlayoffSource::class, 'playoff_group_id');
    }

    /**
     * @return HasMany<Registration, $this>
     */
    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class, 'group_id');
    }
}
