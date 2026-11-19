<?php

namespace App\Models;

use App\Core\Enum\LeagueStatus;
use App\Core\Traits\AdvancedFilter;
use App\Core\Traits\Sortable;
use Database\Factories\LeagueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class League extends Model
{
    /** @use HasFactory<LeagueFactory> */
    use AdvancedFilter, HasFactory, SoftDeletes, Sortable;

    /**
     * @var list<string>
     */
    public array $allowedFilters = ['name'];

    /**
     * @var list<string>
     */
    public array $allowedSorts = ['name', 'created_at'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'status' => LeagueStatus::class,
        'max_active_tournaments' => 'integer',
        'max_teams_per_tournament' => 'integer',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'admin_user_id',
        'name',
        'status',
        'max_active_tournaments',
        'max_teams_per_tournament',
    ];

    public function isActive(): bool
    {
        return $this->status === LeagueStatus::ACTIVE;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    /**
     * @return HasMany<Tournament, $this>
     */
    public function tournaments(): HasMany
    {
        return $this->hasMany(Tournament::class);
    }

    /**
     * @return HasMany<Field, $this>
     */
    public function fields(): HasMany
    {
        return $this->hasMany(Field::class);
    }

    /**
     * @return HasMany<Team, $this>
     */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    /**
     * @return HasMany<LeagueReferee, $this>
     */
    public function referees(): HasMany
    {
        return $this->hasMany(LeagueReferee::class);
    }
}
