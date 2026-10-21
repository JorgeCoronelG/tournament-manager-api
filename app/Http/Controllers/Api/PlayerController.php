<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\PlayerServiceInterface;
use App\Core\BaseApiController;
use App\Http\Requests\Players\UpdatePlayerGenderRequest;
use App\Http\Resources\Players\PlayerResource;
use App\Models\League;
use App\Models\Player;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class PlayerController extends BaseApiController
{
    public function __construct(protected PlayerServiceInterface $playerService) {}

    public function updateGender(UpdatePlayerGenderRequest $request, League $league, Player $player): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->showOne(new PlayerResource($this->playerService->changeGender($league, $player, $request->gender(), $user)));
    }
}
