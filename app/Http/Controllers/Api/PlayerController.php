<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\PlayerServiceInterface;
use App\Core\BaseApiController;
use App\Core\Classes\ListQuery;
use App\Core\Enum\PlayerGender;
use App\Http\Requests\Players\BanPlayerRequest;
use App\Http\Requests\Players\UpdatePlayerGenderRequest;
use App\Http\Resources\Players\PlayerDetailResource;
use App\Http\Resources\Players\PlayerListResource;
use App\Http\Resources\Players\PlayerResource;
use App\Models\League;
use App\Models\Player;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PlayerController extends BaseApiController
{
    public function __construct(protected PlayerServiceInterface $playerService) {}

    public function index(Request $request, League $league): JsonResponse
    {
        $players = $this->playerService->paginateByLeague(
            $league,
            $this->actor($request),
            ListQuery::fromRequest($request),
            $this->parseSearch($request),
            $this->parseEnum($request, 'gender', PlayerGender::class),
            $this->parseBool($request, 'banned')
        );

        return $this->showAll(PlayerListResource::collection($players));
    }

    public function show(Request $request, League $league, Player $player): JsonResponse
    {
        return $this->showOne(new PlayerDetailResource($this->playerService->showInLeague($league, $player, $this->actor($request))));
    }

    public function updateGender(UpdatePlayerGenderRequest $request, League $league, Player $player): JsonResponse
    {
        return $this->showOne(new PlayerResource($this->playerService->changeGender($league, $player, $request->gender(), $this->actor($request))));
    }

    public function ban(BanPlayerRequest $request, League $league, Player $player): JsonResponse
    {
        $result = $this->playerService->ban($league, $player, $this->actor($request), $request->reason());

        return $this->successResponse([
            'player' => (new PlayerListResource($result['player']))->resolve($request),
            'removed_rosters' => $result['removed'],
            'cancelled_requests' => $result['cancelled'],
        ], Response::HTTP_OK);
    }

    public function unban(Request $request, League $league, Player $player): JsonResponse
    {
        return $this->showOne(new PlayerListResource($this->playerService->unban($league, $player, $this->actor($request))));
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
