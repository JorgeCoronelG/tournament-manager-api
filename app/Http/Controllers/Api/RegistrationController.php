<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\RegistrationServiceInterface;
use App\Core\BaseApiController;
use App\Core\Classes\ListQuery;
use App\Core\Enum\RegistrationStatus;
use App\Http\Requests\Registrations\StoreRegistrationRequest;
use App\Http\Resources\Registrations\RegistrationResource;
use App\Http\Resources\Registrations\RegistrationTeamResource;
use App\Models\League;
use App\Models\Registration;
use App\Models\Tournament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RegistrationController extends BaseApiController
{
    public function __construct(protected RegistrationServiceInterface $registrationService) {}

    public function index(Request $request, League $league, Tournament $tournament): JsonResponse
    {
        $listQuery = ListQuery::fromRequest($request);
        $listQuery->sort ??= '-created_at';

        $registrations = $this->registrationService->paginateByTournament(
            $tournament,
            $listQuery,
            $this->parseSearch($request),
            $this->parseEnum($request, 'status', RegistrationStatus::class)
        );

        return $this->showAll(RegistrationResource::collection($registrations));
    }

    public function availableTeams(Request $request, League $league, Tournament $tournament): JsonResponse
    {
        $listQuery = ListQuery::fromRequest($request);
        $listQuery->sort = 'name';

        $teams = $this->registrationService->paginateAvailableTeams($tournament, $listQuery, $this->parseSearch($request));

        return $this->showAll(RegistrationTeamResource::collection($teams));
    }

    public function store(StoreRegistrationRequest $request, League $league, Tournament $tournament): JsonResponse
    {
        $registrations = $this->registrationService->registerTeams($tournament, $request->teamIds());

        return $this->showAll(RegistrationResource::collection($registrations), Response::HTTP_CREATED);
    }

    public function destroy(League $league, Tournament $tournament, Registration $registration): Response
    {
        $this->registrationService->removeRegistration($tournament, $registration);

        return $this->noContentResponse();
    }
}
