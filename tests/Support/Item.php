<?php

namespace Tests\Support;

use App\Core\Traits\AdvancedFilter;
use App\Core\Traits\Sortable;
use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    use AdvancedFilter, Sortable;

    protected $table = 'items';

    protected $fillable = ['name', 'status'];

    /** @var array<int, string> */
    public array $allowedSorts = ['id', 'name', 'status'];

    /** @var array<int, string> */
    public array $allowedFilters = ['id', 'name', 'status'];
}
