<?php

namespace App\Providers;

use App\Models\League;
use App\Models\Team;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        Route::model('league', League::class);

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->string('email').'|'.$request->ip());
        });

        RateLimiter::for('forgot-password', function (Request $request) {
            return Limit::perMinute(5)->by($request->string('email').'|'.$request->ip());
        });

        RateLimiter::for('reset-password', function (Request $request) {
            return Limit::perMinute(5)->by($request->string('email').'|'.$request->ip());
        });

        RateLimiter::for('change-password', function (Request $request) {
            return Limit::perMinute(6)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('activate-account', function (Request $request) {
            return Limit::perMinute(5)->by($request->string('email').'|'.$request->ip());
        });

        RateLimiter::for('resend-invitation', function (Request $request) {
            // `id` en /users/{id}; `user` en /leagues/{league}/referees/{user}; `team` en
            // /leagues/{league}/teams/{team} (con su propio prefijo: los ids no se comparten).
            $team = $request->route('team');

            if ($team !== null) {
                return Limit::perMinute(1)->by('resend-invitation:team:'.($team instanceof Team ? $team->id : (string) $team));
            }

            return Limit::perMinute(1)->by('resend-invitation:'.($request->route('id') ?? $request->route('user')));
        });

        RateLimiter::for('referee-lookup', function (Request $request) {
            return Limit::perMinute(20)->by('referee-lookup:'.($request->user()?->id ?: $request->ip()));
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
