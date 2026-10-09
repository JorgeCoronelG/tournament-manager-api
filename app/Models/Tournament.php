<?php

namespace App\Models;

use App\Core\Enum\TournamentGender;
use App\Core\Enum\TournamentStatus;
use App\Core\Traits\AdvancedFilter;
use App\Core\Traits\Sortable;
use Database\Factories\TournamentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        'gender',
        'min_age',
        'max_age',
        'start_date',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
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
}
