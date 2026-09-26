<?php

namespace Tests\Support;

use App\Core\Traits\AdvancedFilter;
use App\Core\Traits\Sortable;
use Illuminate\Database\Eloquent\Model;

class ItemWithoutSorts extends Model
{
    use AdvancedFilter, Sortable;

    protected $table = 'items';

    protected $fillable = ['name', 'status'];
}
