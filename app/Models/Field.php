<?php

namespace App\Models;

use App\Core\Enum\FieldStatus;
use App\Core\Traits\AdvancedFilter;
use App\Core\Traits\Sortable;
use Database\Factories\FieldFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Field extends Model
{
    /** @use HasFactory<FieldFactory> */
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
     * league_id y status no se asignan en masa: el primero sale de la ruta y el segundo
     * solo cambia con su propio PATCH.
     *
     * @var list<string>
     */
    protected $fillable = ['name'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'status' => FieldStatus::class,
    ];

    /**
     * @return BelongsTo<League, $this>
     */
    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }
}
