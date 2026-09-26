<?php

namespace App\Core\Traits;

use App\Core\Classes\Filter;
use App\Core\Enum\Message;
use App\Core\Enum\OperatorSql;
use App\Exceptions\CustomErrorException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\Response;

trait AdvancedFilter
{
    /**
     * Aplica los filtros agrupados en un solo paréntesis para no romper otros wheres
     * o scopes globales de la consulta. Entre sí se unen con AND, salvo que el filtro indique 'or'.
     * Solo se pueden filtrar los campos de la propiedad pública allowedFilters del modelo.
     *
     * @param  Builder<static>  $query
     * @param  array<int, Filter>  $filters
     * @return Builder<static>
     *
     * @throws CustomErrorException
     */
    public function scopeFilter(Builder $query, array $filters = []): Builder
    {
        if (empty($filters)) {
            return $query;
        }

        if (! property_exists($this, 'allowedFilters')) {
            throw new CustomErrorException(
                Message::getMessageHasNotAllowedFilters(get_class($this)),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        /** @var array<int, string> $allowedFilters */
        $allowedFilters = $this->allowedFilters;

        foreach ($filters as $filter) {
            if (! in_array($filter->field, $allowedFilters, true)) {
                throw new CustomErrorException(Message::INVALID_QUERY_PARAMETER, Response::HTTP_BAD_REQUEST);
            }
        }

        return $query->where(function (Builder $group) use ($filters) {
            foreach ($filters as $filter) {
                $this->filterAdvanced($group, $filter);
            }
        });
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function filterAdvanced(Builder $query, Filter $filter): void
    {
        $field = $filter->field;
        $boolean = $filter->boolean;

        match ($filter->operator) {
            OperatorSql::CONTAIN => $query->where($field, 'LIKE', "%$filter->value%", $boolean),
            OperatorSql::NOT_CONTAIN => $query->where($field, 'NOT LIKE', "%$filter->value%", $boolean),
            OperatorSql::STARTS_WITH => $query->where($field, 'LIKE', "$filter->value%", $boolean),
            OperatorSql::ENDS_WITH => $query->where($field, 'LIKE', "%$filter->value", $boolean),
            OperatorSql::IS_NULL => $query->whereNull($field, $boolean),
            OperatorSql::NOT_NULL => $query->whereNotNull($field, $boolean),
            default => $query->where($field, $filter->operator->value, $filter->value, $boolean),
        };
    }
}
