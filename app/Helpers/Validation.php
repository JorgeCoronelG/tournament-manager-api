<?php

namespace App\Helpers;

use App\Core\Classes\Filter;
use App\Core\Enum\Message;
use App\Core\Enum\OperatorSql;
use App\Core\Enum\QueryParam;
use App\Exceptions\CustomErrorException;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class Validation
{
    public static function getPerPage(?string $queryParam = null): int
    {
        if (is_null($queryParam)) {
            return QueryParam::PAGINATION_ITEMS_DEFAULT;
        }

        return (intval($queryParam) > 0) ? intval($queryParam) : QueryParam::PAGINATION_ITEMS_DEFAULT;
    }

    /**
     * @return Filter[]
     *
     * @throws CustomErrorException
     */
    public static function getFilters(?string $queryParam = null): array
    {
        if (is_null($queryParam)) {
            return [];
        }

        $json = urldecode($queryParam);
        $filters = json_decode($json, true);

        if (! is_array($filters) || ! is_array($filters[QueryParam::FILTERS_FIELD_KEY] ?? null)) {
            throw new CustomErrorException(Message::INVALID_QUERY_PARAMETER, Response::HTTP_BAD_REQUEST);
        }

        $arrayFilters = [];
        foreach ($filters[QueryParam::FILTERS_FIELD_KEY] as $filter) {
            if (
                ! is_array($filter) ||
                ! is_string($filter[QueryParam::FIELD_KEY] ?? null) ||
                ! is_string($filter[QueryParam::OPERATOR_SQL_KEY] ?? null) ||
                ! is_string($filter[QueryParam::BOOLEAN_KEY] ?? 'and')
            ) {
                throw new CustomErrorException(Message::INVALID_QUERY_PARAMETER, Response::HTTP_BAD_REQUEST);
            }

            $operator = OperatorSql::tryFrom($filter[QueryParam::OPERATOR_SQL_KEY]);
            $boolean = strtolower($filter[QueryParam::BOOLEAN_KEY] ?? 'and');
            $value = $filter[QueryParam::VALUE_KEY] ?? null;

            if (is_null($operator) || ! in_array($boolean, ['and', 'or'], true) || (! is_null($value) && ! is_scalar($value))) {
                throw new CustomErrorException(Message::INVALID_QUERY_PARAMETER, Response::HTTP_BAD_REQUEST);
            }

            // IS NULL / IS NOT NULL no necesitan valor.
            $arrayFilters[] = new Filter(
                $filter[QueryParam::FIELD_KEY],
                $value,
                $operator,
                $boolean
            );
        }

        return $arrayFilters;
    }

    /**
     * Función para validar una fecha en formato AAAA/MM/DD
     *
     * @throws CustomErrorException
     */
    public static function validateDate(?string $date = null): ?string
    {
        if (is_null($date)) {
            throw new CustomErrorException(Message::INVALID_QUERY_PARAMETER, Response::HTTP_BAD_REQUEST);
        }

        $dateParse = null;

        if (Str::of($date)->contains('/')) {
            $dateParse = explode('/', $date);
        }

        if (Str::of($date)->contains('-')) {
            $dateParse = explode('-', $date);
        }

        if (is_null($dateParse)) {
            throw new CustomErrorException(Message::INVALID_QUERY_PARAMETER, Response::HTTP_BAD_REQUEST);
        }

        if (
            count($dateParse) !== 3 ||
            ! ctype_digit(implode('', $dateParse)) ||
            ! checkdate((int) $dateParse[1], (int) $dateParse[2], (int) $dateParse[0])
        ) {
            throw new CustomErrorException(Message::INVALID_QUERY_PARAMETER, Response::HTTP_BAD_REQUEST);
        }

        return $date;
    }
}
