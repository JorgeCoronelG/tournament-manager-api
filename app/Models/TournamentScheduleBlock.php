<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bloqueo puntual de una fecha: de una cancha o de todas (field_id nulo), por horas o todo el día (horas nulas).
 */
class TournamentScheduleBlock extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var array<string, string>
     */
    protected $casts = ['date' => 'date:Y-m-d'];

    /**
     * @return BelongsTo<Field, $this>
     */
    public function field(): BelongsTo
    {
        return $this->belongsTo(Field::class)->withTrashed();
    }
}
