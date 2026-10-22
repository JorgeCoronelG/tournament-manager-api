<?php

namespace App\Core\Classes;

use App\Core\Enum\Message;
use App\Core\Enum\QueryParam;
use App\Exceptions\CustomErrorException;
use App\Helpers\Validation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Parámetros de un listado (filtros, orden y tamaño de página) independientes de HTTP.
 * El controller la construye desde el request y el servicio solo recibe este objeto,
 * así el servicio se puede usar también desde jobs, comandos o tests.
 */
class ListQuery
{
    /**
     * @param  Filter[]  $filters
     */
    public function __construct(
        public array $filters = [],
        public ?string $sort = null,
        public int $perPage = QueryParam::PAGINATION_ITEMS_DEFAULT,
        public bool $skipPaging = QueryParam::SKIP_PAGINATION_DEFAULT
    ) {}

    /**
     * @throws CustomErrorException
     */
    public static function fromRequest(Request $request): self
    {
        return new self(
            Validation::getFilters(self::stringParam($request, QueryParam::FILTERS_KEY)),
            self::stringParam($request, QueryParam::ORDER_BY_KEY),
            Validation::getPerPage(self::stringParam($request, QueryParam::PAGINATION_KEY)),
            self::skipPaging(self::stringParam($request, QueryParam::SKIP_PAGINATION_KEY))
        );
    }

    /**
     * Pagina el listado o, con `skip_paging`, devuelve todas las filas en una sola página: la respuesta conserva
     * `data`, `links` y `meta`, así que el front no necesita otro formato. Pensado para listas de opciones.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $builder
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginate(Builder $builder): LengthAwarePaginator
    {
        if (! $this->skipPaging) {
            return $builder->paginate($this->perPage)->withQueryString();
        }

        $rows = $builder->get();

        return (new LengthAwarePaginator($rows, $rows->count(), max($rows->count(), 1), 1, ['path' => Paginator::resolveCurrentPath()]))
            ->withQueryString();
    }

    /**
     * @throws CustomErrorException
     */
    private static function skipPaging(?string $value): bool
    {
        if ($value === null || $value === '') {
            return QueryParam::SKIP_PAGINATION_DEFAULT;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            ?? throw new CustomErrorException(Message::INVALID_QUERY_PARAMETER, Response::HTTP_BAD_REQUEST);
    }

    /**
     * Un parámetro repetido como arreglo (?q[]=x) no es válido: se responde 400 en lugar de un TypeError.
     *
     * @throws CustomErrorException
     */
    private static function stringParam(Request $request, string $key): ?string
    {
        $value = $request->get($key);

        if ($value === null) {
            return null;
        }

        if (! is_string($value) && ! is_int($value)) {
            throw new CustomErrorException(Message::INVALID_QUERY_PARAMETER, Response::HTTP_BAD_REQUEST);
        }

        return (string) $value;
    }
}
