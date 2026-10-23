<?php

namespace App\Models;

use App\Core\Enum\TournamentGroupType;
use App\Core\Enum\TournamentStatus;
use App\Helpers\PlayoffRounds;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Grupo de un torneo: de fase regular o de liguilla (type playoff, con sus equipos en playoff_sources).
 */
class TournamentGroup extends Model
{
    use SoftDeletes;

    /** Nombre del grupo que se crea con cada torneo. */
    public const DEFAULT_NAME = 'Fase regular';

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'type' => TournamentGroupType::class,
        'start_date' => 'date',
        'round_dates' => 'array',
    ];

    /**
     * @return BelongsTo<Tournament, $this>
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    /**
     * Orígenes de los equipos de esta liguilla.
     *
     * @return HasMany<PlayoffSource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(PlayoffSource::class, 'playoff_group_id');
    }

    /**
     * Total de equipos de una liguilla: suma de los rangos de sus fuentes.
     */
    public function totalTeams(): int
    {
        return $this->sources->sum(fn (PlayoffSource $source) => $source->position_end - $source->position_start + 1);
    }

    /**
     * Si la liguilla se puede editar o borrar: en planeación siempre; en curso solo sin partidos; después no.
     */
    public function editableIn(Tournament $tournament): bool
    {
        return match ($tournament->status) {
            TournamentStatus::PLANNING => true,
            TournamentStatus::IN_PROGRESS => ! DB::table('matches')->where('group_id', $this->id)->whereNull('deleted_at')->exists(),
            default => false,
        };
    }

    /**
     * Fecha de cada ronda, de la primera a la final: round_dates si existe; si no, start_date + (n - 1) * round_interval_days
     * (n desde 1); null en cada ronda sin start_date.
     *
     * @return list<string|null>
     */
    public function resolvedRoundDates(): array
    {
        if ($this->type !== TournamentGroupType::PLAYOFF) {
            return [];
        }

        $rounds = PlayoffRounds::count($this->totalTeams());
        $stored = $this->round_dates;

        if (is_array($stored) && $stored !== [] && count($stored) === $rounds) {
            return array_values($stored);
        }

        $dates = [];

        for ($n = 1; $n <= $rounds; $n++) {
            $dates[] = $this->start_date?->copy()->addDays(($n - 1) * (int) $this->round_interval_days)->toDateString();
        }

        return $dates;
    }

    /**
     * Fecha de la final: la de la última ronda; null sin fechas.
     */
    public function finalDate(): ?string
    {
        $dates = $this->resolvedRoundDates();

        return $dates === [] ? null : end($dates);
    }

    /**
     * @return HasMany<Registration, $this>
     */
    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class, 'group_id');
    }
}
