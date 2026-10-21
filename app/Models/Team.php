<?php

namespace App\Models;

use App\Core\Enum\Sport;
use App\Core\Enum\TeamStatus;
use App\Core\Traits\AdvancedFilter;
use App\Core\Traits\Sortable;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use AdvancedFilter, HasFactory, SoftDeletes, Sortable;

    /**
     * @var list<string>
     */
    public array $allowedFilters = ['name', 'status'];

    /**
     * @var list<string>
     */
    public array $allowedSorts = ['name', 'created_at'];

    /**
     * league_id, manager_user_id, logo_path y status no se asignan en masa: la liga sale de la
     * ruta, el delegado y el logo tienen sus propios endpoints y el estado su propio PATCH.
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'sport'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sport' => Sport::class,
        'status' => TeamStatus::class,
    ];

    /**
     * @return BelongsTo<League, $this>
     */
    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    /**
     * @return HasMany<Registration, $this>
     */
    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    /**
     * URL completa del logo (la app móvil la usa tal cual) o null si no tiene.
     */
    public function logoUrl(): ?string
    {
        return $this->logo_path === null ? null : Storage::disk('public')->url($this->logo_path);
    }
}
