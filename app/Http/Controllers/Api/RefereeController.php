<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\LeagueRefereeServiceInterface;
use App\Core\BaseApiController;
use App\Core\Classes\ListQuery;
use App\Core\Enum\LeagueRefereeStatus;
use App\Http\Requests\Referees\RefereeUserCodeRequest;
use App\Http\Requests\Referees\SaveRefereeRequest;
use App\Http\Requests\Referees\UpdateRefereeStatusRequest;
use App\Http\Resources\Referees\RefereeLookupResource;
use App\Http\Resources\Referees\RefereeResource;
use App\Models\League;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * El árbitro se identifica en la ruta por su user id ({user}); el servicio lo acota a la liga
 * de la ruta y responde 404 si no está vinculado a ella.
 */
class RefereeController extends BaseApiController
{
    public function __construct(protected LeagueRefereeServiceInterface $refereeService) {}

    public function index(Request $request, League $league): JsonResponse
    {
        $referees = $this->refereeService->paginateByLeague(
            $league,
            ListQuery::fromRequest($request),
            $this->parseSearch($request),
            $this->parseEnum($request, 'status', LeagueRefereeStatus::class)
        );

        return $this->showAll(RefereeResource::collection($referees));
    }

    public function store(SaveRefereeRequest $request, League $league): JsonResponse
    {
        $referee = $this->refereeService->createReferee($league, $request->toData());

        return $this->showOne(new RefereeResource($referee), Response::HTTP_CREATED);
    }

    public function lookup(RefereeUserCodeRequest $request, League $league): JsonResponse
    {
        return $this->showOne(new RefereeLookupResource($this->refereeService->lookup($league, $request->userCode())));
    }

    public function attach(RefereeUserCodeRequest $request, League $league): JsonResponse
    {
        return $this->showOne(new RefereeResource($this->refereeService->attach($league, $request->userCode())));
    }

    public function update(SaveRefereeRequest $request, League $league, int $user): JsonResponse
    {
        return $this->showOne(new RefereeResource($this->refereeService->updateReferee($league, $user, $request->toData())));
    }

    public function updateStatus(UpdateRefereeStatusRequest $request, League $league, int $user): JsonResponse
    {
        $status = LeagueRefereeStatus::from($request->string('status')->toString());

        return $this->showOne(new RefereeResource($this->refereeService->changeStatus($league, $user, $status)));
    }

    public function destroy(League $league, int $user): Response
    {
        $this->refereeService->detach($league, $user);

        return $this->noContentResponse();
    }

    public function resendInvitation(League $league, int $user): JsonResponse
    {
        return $this->showOne(new RefereeResource($this->refereeService->resendInvitation($league, $user)));
    }
}
