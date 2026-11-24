<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\PlayerProfileServiceInterface;
use App\Core\BaseApiController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * App móvil, rol Jugador: pestaña "Mi QR" (su código, sus equipos y sus próximos partidos).
 */
class PlayerProfileController extends BaseApiController
{
    public function __construct(protected PlayerProfileServiceInterface $playerProfileService) {}

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->successResponse($this->playerProfileService->profileOf($user), Response::HTTP_OK);
    }
}
