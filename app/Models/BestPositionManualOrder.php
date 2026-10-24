<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Orden manual de un candidato empatado en la comparativa de una fuente de mejores de una posición.
 */
class BestPositionManualOrder extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [];
}
