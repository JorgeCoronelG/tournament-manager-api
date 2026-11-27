<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\RefereeMatchServiceInterface;
use App\Core\BaseApiController;
use App\Http\Requests\Referee\SyncRefereeMatchRequest;
use App\Http\Resources\Referee\RefereeMatchResource;
use App\Models\GameMatch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * App móvil, rol Árbitro: sus partidos, la plantilla de cada uno (para validar sin internet) y el envío por lote de
 * escaneos, eventos y marcador.
 */
class RefereeMatchController extends BaseApiController
{
    /** Días hacia atrás y hacia adelante que trae la lista si no se piden fechas. */
    private const DAYS_BACK = 7;

    private const DAYS_AHEAD = 30;

    public function __construct(protected RefereeMatchServiceInterface $refereeMatchService) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $today = CarbonImmutable::today();
        $from = $request->filled('from') ? CarbonImmutable::parse($request->string('from')->toString()) : $today->subDays(self::DAYS_BACK);
        $to = $request->filled('to') ? CarbonImmutable::parse($request->string('to')->toString()) : $today->addDays(self::DAYS_AHEAD);

        return $this->successResponse(
            ['data' => RefereeMatchResource::collection($this->refereeMatchService->matchesOf($this->actor($request), $from, $to))],
            Response::HTTP_OK
        );
    }

    public function rosters(Request $request, GameMatch $match): JsonResponse
    {
        return $this->successResponse($this->refereeMatchService->rostersOf($match, $this->actor($request)), Response::HTTP_OK);
    }

    public function sync(SyncRefereeMatchRequest $request, GameMatch $match): JsonResponse
    {
        return $this->successResponse(
            $this->refereeMatchService->sync($match, $this->actor($request), $request->payload()),
            Response::HTTP_OK
        );
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
