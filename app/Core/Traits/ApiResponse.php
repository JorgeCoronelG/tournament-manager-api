<?php

namespace App\Core\Traits;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

trait ApiResponse
{
    use PaginateCollection;

    /**
     * Función que retorna una respuesta JSON exitosa
     *
     * @param  ResourceCollection|JsonResource|array<string, mixed>  $data
     */
    protected function successResponse(
        ResourceCollection|JsonResource|array $data,
        int $code
    ): JsonResponse {
        return response()->json($data, $code);
    }

    /**
     * Función que retorna una respuesta JSON con contenido de algún archivo
     *
     * @param  array<string, string>  $headers
     */
    protected function fileResponse(string $pathFile, array $headers = []): BinaryFileResponse
    {
        return response()->file($pathFile, $headers);
    }

    /**
     * Función que retorna una respuesta JSON errones
     *
     * @param  array<string, mixed>|string  $message
     */
    protected function errorResponse(array|string $message, int $code): JsonResponse
    {
        return response()->json(['code' => $code, 'error' => $message], $code);
    }

    /**
     * Función que retorna una respuesta 204 no content
     */
    protected function noContentResponse(): Response
    {
        return response()->noContent();
    }

    /**
     * Función que retorna un JSON con un listado de registros.
     * Si la colección viene paginada se devuelve con data, links y meta (ver PaginateCollection);
     * de lo contrario, solo la lista de registros.
     */
    protected function showAll(ResourceCollection $collection, int $code = 200): JsonResponse
    {
        if ($collection->resource instanceof LengthAwarePaginator) {
            return $this->successResponse($this->getPaginationCollection($collection), $code);
        }

        return $this->successResponse($collection, $code);
    }

    /**
     * Función que retorna un JSON con un registro
     */
    protected function showOne(JsonResource $resource, int $code = 200): JsonResponse
    {
        return $this->successResponse($resource, $code);
    }
}
