<?php

namespace App\Core;

use App\Core\Enum\Message;
use App\Core\Traits\ApiResponse;
use App\Exceptions\CustomErrorException;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BaseApiController extends Controller
{
    use ApiResponse;

    /**
     * Parámetro `search` de un listado; vacío equivale a no enviarlo.
     */
    protected function parseSearch(Request $request): ?string
    {
        $search = trim((string) $request->string('search'));

        return $search === '' ? null : $search;
    }

    /**
     * Parámetro booleano de un listado (`true`/`false`, `1`/`0`); vacío se ignora y uno inválido responde 400.
     *
     * @throws CustomErrorException
     */
    protected function parseBool(Request $request, string $key): ?bool
    {
        if (! $request->filled($key)) {
            return null;
        }

        $value = filter_var($request->query($key), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($value === null) {
            throw new CustomErrorException(Message::INVALID_QUERY_PARAMETER, Response::HTTP_BAD_REQUEST);
        }

        return $value;
    }

    /**
     * Parámetro de un listado que debe ser un valor de un enum; vacío se ignora y uno inválido responde 400.
     *
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null
     *
     * @throws CustomErrorException
     */
    protected function parseEnum(Request $request, string $key, string $enum): ?\BackedEnum
    {
        if (! $request->filled($key)) {
            return null;
        }

        $value = $request->query($key);
        $case = is_string($value) ? $enum::tryFrom($value) : null;

        if ($case === null) {
            throw new CustomErrorException(Message::INVALID_QUERY_PARAMETER, Response::HTTP_BAD_REQUEST);
        }

        return $case;
    }
}
