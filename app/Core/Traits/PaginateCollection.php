<?php

namespace App\Core\Traits;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Resources\Json\ResourceCollection;
use InvalidArgumentException;

/**
 * @author JorgeCoronelG
 *
 * @version 1.0
 */
trait PaginateCollection
{
    /**
     * Función para retornar una respuesta de ResourceCollection con paginación
     *
     * @return array{data: mixed, links: array<string, string|null>, meta: array<string, int|null>}
     */
    public function getPaginationCollection(ResourceCollection $collection): array
    {
        $paginator = $collection->resource;

        if (! $paginator instanceof LengthAwarePaginator) {
            throw new InvalidArgumentException('La colección debe venir de un paginador con total de registros (paginate).');
        }

        return [
            'data' => $collection->collection,
            'links' => [
                'first' => ($paginator->currentPage() <= 1) ? null : $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
