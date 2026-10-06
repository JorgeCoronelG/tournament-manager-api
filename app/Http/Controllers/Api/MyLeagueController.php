<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\LeagueServiceInterface;
use App\Core\BaseApiController;
use App\Core\Classes\ListQuery;
use App\Core\Enum\QueryParam;
use App\Helpers\Validation;
use App\Http\Resources\Leagues\MyLeagueResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyLeagueController extends BaseApiController
{
    public function __construct(protected LeagueServiceInterface $leagueService) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = $request->query(QueryParam::PAGINATION_KEY);

        // Orden fijo por nombre; ni `sort` ni `q` aplican a este listado.
        $listQuery = new ListQuery([], 'name', Validation::getPerPage(is_string($perPage) ? $perPage : null));

        $search = trim((string) $request->string('search'));

        /** @var User $user */
        $user = $request->user();

        $leagues = $this->leagueService->paginateAccessible($listQuery, $user, $search === '' ? null : $search);

        return $this->showAll(MyLeagueResource::collection($leagues));
    }
}
