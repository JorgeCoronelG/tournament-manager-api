<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\DashboardServiceInterface;
use App\Core\BaseApiController;
use App\Models\League;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DashboardController extends BaseApiController
{
    public function __construct(protected DashboardServiceInterface $dashboardService) {}

    public function summary(Request $request): JsonResponse
    {
        return $this->successResponse($this->dashboardService->summary($this->parseId($request, 'league_id')), Response::HTTP_OK);
    }

    public function leagueSummary(League $league): JsonResponse
    {
        return $this->successResponse($this->dashboardService->leagueSummary($league), Response::HTTP_OK);
    }
}
