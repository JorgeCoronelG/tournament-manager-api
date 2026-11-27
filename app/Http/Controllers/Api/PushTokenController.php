<?php

namespace App\Http\Controllers\Api;

use App\Core\BaseApiController;
use App\Http\Requests\Auth\PushTokenRequest;
use App\Models\PushToken;
use App\Models\User;
use Illuminate\Http\Response;

/**
 * La app registra aquí el token de notificaciones push del celular al entrar, y lo quita al cerrar sesión.
 */
class PushTokenController extends BaseApiController
{
    public function store(PushTokenRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        // El token es del celular: si antes era de otra cuenta, ahora es de la que entró.
        PushToken::query()->updateOrCreate(
            ['token' => $request->token()],
            ['user_id' => $user->id, 'platform' => $request->platform()],
        );

        return $this->noContentResponse();
    }

    public function destroy(PushTokenRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $user->pushTokens()->where('token', $request->token())->delete();

        return $this->noContentResponse();
    }
}
