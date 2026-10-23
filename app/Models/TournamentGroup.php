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
        'round_legs' => 'array',
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
     * Partidos de cada ronda (1 o 2), de la primera a la final: round_legs si coincide con las rondas; si no, 1 por ronda.
     *
     * @return list<int>
     */
    public function legsPerRound(): array
    {
        $rounds = PlayoffRounds::count($this->totalTeams());
        $stored = $this->round_legs;

        if (is_array($stored) && count($stored) === $rounds) {
            return array_values(array_map(fn (mixed $legs) => $legs === 2 ? 2 : 1, $stored));
        }

        return array_fill(0, $rounds, 1);
    }

    /**
     * Total de partidos de ronda de la liguilla: suma de los partidos de cada ronda.
     */
    public function matchCount(): int
    {
        return array_sum($this->legsPerRound());
    }

    /**
     * Fecha de cada partido de ronda, en orden: round_dates si coincide con el total de partidos; si no,
     * start_date + k * round_interval_days para el partido k (desde 0); null en cada uno sin start_date.
     *
     * @return list<string|null>
     */
    public function resolvedMatchDates(): array
    {
        if ($this->type !== TournamentGroupType::PLAYOFF) {
            return [];
        }

        $matches = $this->matchCount();
        $stored = $this->round_dates;

        if (is_array($stored) && $stored !== [] && count($stored) === $matches) {
            return array_values($stored);
        }

        $dates = [];

        for ($k = 0; $k < $matches; $k++) {
            $dates[] = $this->start_date?->copy()->addDays($k * (int) $this->round_interval_days)->toDateString();
        }

        return $dates;
    }

    /**
     * Rondas con su nombre, partidos y la fecha (o null) de cada partido.
     *
     * @return list<array{name: string, legs: int, dates: list<string|null>}>
     */
    public function roundsDetail(): array
    {
        $dates = $this->resolvedMatchDates();
        $detail = [];
        $offset = 0;

        foreach (PlayoffRounds::names($this->totalTeams()) as $index => $name) {
            $legs = $this->legsPerRound()[$index] ?? 1;
            $detail[] = ['name' => $name, 'legs' => $legs, 'dates' => array_slice($dates, $offset, $legs)];
            $offset += $legs;
        }

        return $detail;
    }

    /**
     * Fecha de la final: la del último partido de la última ronda; null sin fechas.
     */
    public function finalDate(): ?string
    {
        $dates = $this->resolvedMatchDates();

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
