<?php

namespace App\Http\Middleware;

use App\Models\Role;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Permission
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     *
     * @throws AuthenticationException
     * @throws AuthorizationException
     */
    public function handle(Request $request, Closure $next, string|int ...$roleIds): Response
    {
        $user = auth()->user();

        if ($user === null) {
            throw new AuthenticationException;
        }

        // Los parámetros de middleware (permission:1,2) llegan como string, por eso se compara como string.
        $userRoleIds = $user->roles->map(fn (Role $role): string => (string) $role->id)->all();
        $allowedRoleIds = array_map('strval', $roleIds);

        if (array_intersect($userRoleIds, $allowedRoleIds) === []) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}
