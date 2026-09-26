<?php

namespace App\Core\Contracts;

use App\Core\Classes\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

interface ScopeFilterInterface
{
    /**
     * @param  Builder<Model>  $query
     * @param  Filter[]  $filters
     * @return Builder<Model>
     */
    public function scopeFilter(Builder $query, array $filters = []): Builder;
}
