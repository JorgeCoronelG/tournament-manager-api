<?php

namespace App\Models;

use App\Core\Enum\LeagueRefereeStatus;
use Database\Factories\LeagueRefereeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Vínculo de un árbitro (usuario con rol referee) con una liga. Darlo de baja aquí
 * no toca su cuenta, sus roles ni sus otras ligas.
 */
class LeagueReferee extends Model
{
    /** @use HasFactory<LeagueRefereeFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'league_referees';

    /**
     * league_id y user_id salen de la ruta o del servicio, nunca del body.
     *
     * @var list<string>
     */
    protected $fillable = [
        'status',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'status' => LeagueRefereeStatus::class,
    ];

    /**
     * @return BelongsTo<League, $this>
     */
    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
