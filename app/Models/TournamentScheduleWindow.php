<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ventana de juego semanal de una cancha (día de la semana 1 = lunes ... 7 = domingo y rango de horas).
 */
class TournamentScheduleWindow extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return BelongsTo<Field, $this>
     */
    public function field(): BelongsTo
    {
        return $this->belongsTo(Field::class)->withTrashed();
    }
}
