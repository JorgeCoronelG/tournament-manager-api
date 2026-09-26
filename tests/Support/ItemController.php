<?php

namespace Tests\Support;

use App\Core\BaseApiController;
use Illuminate\Http\JsonResponse;

class ItemController extends BaseApiController
{
    public function paginated(): JsonResponse
    {
        return $this->showAll(ItemResource::collection(Item::query()->orderBy('id')->paginate(2)));
    }

    public function all(): JsonResponse
    {
        return $this->showAll(ItemResource::collection(Item::query()->orderBy('id')->get()));
    }

    public function one(): JsonResponse
    {
        return $this->showOne(new ItemResource(Item::query()->firstOrFail()));
    }
}
