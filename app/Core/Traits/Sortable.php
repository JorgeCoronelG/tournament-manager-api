<?php

namespace App\Core\Traits;

use App\Core\Enum\Message;
use App\Exceptions\CustomErrorException;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\Response;

trait Sortable
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     *
     * @throws CustomErrorException
     */
    public function scopeApplySort(Builder $query, ?string $sort = null): Builder
    {
        if (is_null($sort)) {
            return $query;
        }

        if (! property_exists($this, 'allowedSorts')) {
            throw new CustomErrorException(Message::getMessageHasNotAllowedSorts(get_class($this)), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        /** @var array<int, string> $allowedSorts */
        $allowedSorts = $this->allowedSorts;
        $sortFields = explode(',', $sort);

        foreach ($sortFields as $sortField) {
            $direction = 'asc';

            if (str_starts_with($sortField, '-')) {
                $direction = 'desc';
                $sortField = substr($sortField, 1);
            }

            if (! in_array($sortField, $allowedSorts, true)) {
                throw new CustomErrorException(Message::INVALID_QUERY_PARAMETER, Response::HTTP_BAD_REQUEST);
            }

            $query->orderBy($sortField, $direction);
        }

        return $query;
    }
}
