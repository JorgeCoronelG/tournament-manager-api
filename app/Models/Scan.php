<?php

namespace App\Models;

use App\Core\Enum\ScanMethod;
use App\Core\Enum\ScanResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Escaneo del árbitro. Se crea sin conexión en el celular y llega por lote; client_uuid evita duplicados.
 * El resultado lo calcula el servidor (RefereeMatchService::scanResult), no el celular.
 */
class Scan extends Model
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
        'method' => ScanMethod::class,
        'result' => ScanResult::class,
        'scanned_at' => 'datetime',
        'synced_at' => 'datetime',
        'match_id' => 'integer',
        'player_id' => 'integer',
        'registration_id' => 'integer',
    ];

    /**
     * @return BelongsTo<GameMatch, $this>
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    /**
     * @return BelongsTo<Player, $this>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
