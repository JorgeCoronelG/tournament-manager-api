<?php

namespace App\Models;

use App\Core\Enum\Sport;
use App\Core\Enum\TournamentGender;
use App\Core\Enum\TournamentStatus;
use App\Core\Traits\AdvancedFilter;
use App\Core\Traits\Sortable;
use Database\Factories\TournamentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Tournament extends Model
{
    /** @use HasFactory<TournamentFactory> */
    use AdvancedFilter, HasFactory, SoftDeletes, Sortable;

    /** En esta versión solo hay jugadores +18. */
    public const MIN_PLAYER_AGE = 18;

    public const MAX_PLAYER_AGE = 99;

    /**
     * @var list<string>
     */
    public array $allowedFilters = ['name', 'gender', 'status', 'start_date'];

    /**
     * @var list<string>
     */
    public array $allowedSorts = ['name', 'start_date', 'created_at'];

    /**
     * league_id y status no se asignan en masa: el primero sale de la ruta y el segundo
     * solo cambia por la transición de estado.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'sport',
        'gender',
        'min_age',
        'max_age',
        'max_roster_size',
        'min_matches_for_playoffs',
        'start_date',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sport' => Sport::class,
        'gender' => TournamentGender::class,
        'status' => TournamentStatus::class,
        'start_date' => 'date',
    ];

    /**
     * @return BelongsTo<League, $this>
     */
    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }

    /**
     * @return HasMany<Registration, $this>
     */
    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    /**
     * Si el torneo ya entró a la liguilla (tiene llaves eliminatorias). Desde entonces las plantillas se cierran.
     */
    public function playoffsStarted(): bool
    {
        return DB::table('brackets')
            ->join('tournament_groups', 'tournament_groups.id', '=', 'brackets.group_id')
            ->where('tournament_groups.tournament_id', $this->id)
            ->exists();
    }
}
