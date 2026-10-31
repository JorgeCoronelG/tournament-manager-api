<?php

namespace App\Models;

use App\Core\Enum\RosterStatus;
use App\Core\Traits\Sortable;
use Database\Factories\RosterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Roster extends Model
{
    /** @use HasFactory<RosterFactory> */
    use HasFactory, Sortable;

    /**
     * @var list<string>
     */
    public array $allowedSorts = ['jersey_number', 'status', 'requested_at', 'removed_at'];

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
     * Equipo al que pertenecía el jugador cuando se creó la solicitud (puede estar eliminado).
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Player, $this>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by_user_id');
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
