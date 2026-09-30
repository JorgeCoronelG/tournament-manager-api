<?php

namespace App\Models;

use App\Core\Traits\AdvancedFilter;
use App\Core\Traits\Sortable;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use AdvancedFilter, HasFactory, SoftDeletes, Sortable;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    public array $allowedFilters = ['id', 'name'];

    /**
     * @var list<string>
     */
    public array $allowedSorts = ['id', 'name'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }
}
