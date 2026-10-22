<?php

namespace App\Models;

use App\Core\Enum\RegistrationStatus;
use App\Core\Traits\AdvancedFilter;
use App\Core\Traits\Sortable;
use Database\Factories\RegistrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Registration extends Model
{
    /** @use HasFactory<RegistrationFactory> */
    use AdvancedFilter, HasFactory, SoftDeletes, Sortable;

    /**
     * @var list<string>
     */
    public array $allowedFilters = ['status'];

    /**
     * @var list<string>
     */
    public array $allowedSorts = ['created_at', 'status'];

    /**
     * Nada se asigna en masa: equipo y torneo salen de la ruta/servicio y el estado solo cambia por flujo.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'status' => RegistrationStatus::class,
    ];

    /**
     * Incluye equipos eliminados: una inscripción en baja puede conservar un equipo que ya no existe.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class)->withTrashed();
    }

    /**
     * @return HasMany<Roster, $this>
     */
    public function rosters(): HasMany
    {
        return $this->hasMany(Roster::class);
    }

    /**
     * @return BelongsTo<Tournament, $this>
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }
}
