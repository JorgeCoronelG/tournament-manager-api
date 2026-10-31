<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reemplazo de equipo en una inscripción: quién, cuándo y por qué.
 */
class RegistrationTeamChange extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var array<string, string>
     */
    protected $casts = ['created_at' => 'datetime'];

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function oldTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'old_team_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function newTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'new_team_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    /**
     * @return BelongsTo<Registration, $this>
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }
}
