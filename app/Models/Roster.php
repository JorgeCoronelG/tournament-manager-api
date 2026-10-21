<?php

namespace App\Models;

use App\Core\Enum\RosterStatus;
use Database\Factories\RosterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Roster extends Model
{
    /** @use HasFactory<RosterFactory> */
    use HasFactory;

    /**
     * Nada se asigna en masa: el roster se arma desde su propio flujo.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'status' => RosterStatus::class,
        'requested_at' => 'datetime',
        'responded_at' => 'datetime',
        'removed_at' => 'datetime',
        'rejoin_authorized_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Player, $this>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /**
     * @return BelongsTo<Tournament, $this>
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    /**
     * @return BelongsTo<Registration, $this>
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }
}
