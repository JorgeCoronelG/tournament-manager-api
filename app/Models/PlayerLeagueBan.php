<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Veto de un jugador en una liga. Vigente mientras `lifted_at` sea null; levantarlo conserva el historial.
 */
class PlayerLeagueBan extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'lifted_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function bannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'banned_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function liftedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lifted_by_user_id');
    }
}
