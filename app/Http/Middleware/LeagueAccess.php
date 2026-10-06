<?php

namespace App\Http\Middleware;

use App\Models\League;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autoriza el acceso a la liga de la ruta (/api/leagues/{league}/...).
 * El parámetro {league} se resuelve con route model binding (ver RouteServiceProvider),
 * por lo que una liga inexistente o eliminada responde 404 antes de llegar aquí.
 */
class LeagueAccess
{
    /**
     * @param  Closure(Request): (Response)  $next
     *
     * @throws AuthenticationException
     * @throws AuthorizationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new AuthenticationException;
        }

        $league = $request->route('league');

        if (! $league instanceof League) {
            throw new \LogicException('league.access requiere un parámetro de ruta {league}.');
        }

        if (! $user->can('access', $league)) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}
