<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Core\Enum\LoginClient;
use App\Core\Enum\Role as RoleEnum;
use App\Core\Enum\UserAccountStatus;
use App\Core\Traits\AdvancedFilter;
use App\Core\Traits\Sortable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use AdvancedFilter, HasApiTokens, HasFactory, Notifiable, SoftDeletes, Sortable;

    /**
     * @var list<string>
     */
    public array $allowedFilters = ['first_name', 'last_name', 'email', 'phone', 'user_code'];

    /**
     * @var list<string>
     */
    public array $allowedSorts = ['first_name', 'last_name', 'created_at'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'user_code',
        'password',
        'photo_url',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
    ];

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    /**
     * Ligas que administra (el usuario es su encargado).
     *
     * @return HasMany<League, $this>
     */
    public function leagues(): HasMany
    {
        return $this->hasMany(League::class, 'admin_user_id');
    }

    /**
     * Equipos de los que es delegado (puede serlo en varias ligas).
     *
     * @return HasMany<Team, $this>
     */
    public function managedTeams(): HasMany
    {
        return $this->hasMany(Team::class, 'manager_user_id');
    }

    /**
     * Vínculos de este usuario como árbitro con ligas.
     *
     * @return HasMany<LeagueReferee, $this>
     */
    public function leagueReferees(): HasMany
    {
        return $this->hasMany(LeagueReferee::class);
    }

    /**
     * Si alguno de sus roles puede iniciar sesión en el cliente (ver Role::allowedForClient()).
     */
    public function hasRoleForClient(LoginClient $client): bool
    {
        $allowed = array_map(fn (RoleEnum $role) => $role->value, RoleEnum::allowedForClient($client));

        return $this->roles->contains(fn (Role $role) => in_array($role->id, $allowed, true));
    }

    public function accountStatus(): UserAccountStatus
    {
        if ($this->email_verified_at === null) {
            return UserAccountStatus::PENDING;
        }

        return $this->is_active ? UserAccountStatus::ACTIVE : UserAccountStatus::INACTIVE;
    }
}
