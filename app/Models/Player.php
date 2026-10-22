<?php

namespace App\Models;

use App\Core\Enum\PlayerGender;
use Database\Factories\PlayerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Player extends Model
{
    /** @use HasFactory<PlayerFactory> */
    use HasFactory, SoftDeletes;

    /**
     * user_id y qr_code no se asignan en masa. La rama se asigna aquí al registrarse; corregirla después
     * solo se hace por PlayerService::changeGender, que valida y deja auditoría.
     *
     * @var list<string>
     */
    protected $fillable = ['birth_date', 'gender'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'birth_date' => 'date',
        'gender' => PlayerGender::class,
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Roster, $this>
     */
    public function rosters(): HasMany
    {
        return $this->hasMany(Roster::class);
    }

    /**
     * @return HasMany<PlayerLeagueBan, $this>
     */
    public function leagueBans(): HasMany
    {
        return $this->hasMany(PlayerLeagueBan::class);
    }

    /**
     * @return HasMany<PlayerGenderChange, $this>
     */
    public function genderChanges(): HasMany
    {
        return $this->hasMany(PlayerGenderChange::class);
    }
}
