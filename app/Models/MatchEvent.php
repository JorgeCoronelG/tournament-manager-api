<?php

namespace App\Models;

use App\Core\Enum\MatchEventType;
use App\Core\Enum\MatchPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gol, tarjeta o cambio registrado por el árbitro. No se borra: un evento capturado por error se marca is_voided.
 * registration_id es el equipo del jugador que lo comete (en un autogol el gol cuenta para el rival).
 */
class MatchEvent extends Model
{
    /**
     * Solo se escribe desde la sincronización del árbitro.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'type' => MatchEventType::class,
        'period' => MatchPeriod::class,
        'minute' => 'integer',
        'is_voided' => 'boolean',
        'recorded_at' => 'datetime',
        'synced_at' => 'datetime',
        'match_id' => 'integer',
        'registration_id' => 'integer',
        'player_id' => 'integer',
        'related_player_id' => 'integer',
    ];

    /**
     * @return BelongsTo<GameMatch, $this>
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }
}
