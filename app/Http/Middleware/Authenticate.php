<?php

namespace App\Http\Middleware;

use App\Core\Enum\Message;
use App\Core\Traits\ApiResponse;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    use ApiResponse;

    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): JsonResponse|string|null
    {
        return $this->errorResponse(Message::AUTHENTICATION_EXCEPTION, 401);
    }
}
