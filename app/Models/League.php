<?php

namespace App\Models;

use App\Core\Traits\AdvancedFilter;
use App\Core\Traits\Sortable;
use Database\Factories\LeagueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
     * @var list<string>
     */
    protected $fillable = [
        'admin_user_id',
        'name',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
