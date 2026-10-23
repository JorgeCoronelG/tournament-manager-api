<?php

use App\Core\Enum\Role as RoleEnum;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FieldController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\LeagueController;
use App\Http\Controllers\Api\MatchController;
use App\Http\Controllers\Api\MyLeagueController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PlayerController;
use App\Http\Controllers\Api\RefereeController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\RosterController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\TournamentController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword'])->middleware('throttle:forgot-password');
Route::post('/reset-password', [PasswordResetController::class, 'resetPassword'])->middleware('throttle:reset-password');
Route::post('/activate-account', [PasswordResetController::class, 'activateAccount'])->middleware('throttle:activate-account');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/user', [AuthController::class, 'me']);

    Route::put('/user/password', [AuthController::class, 'changePassword'])->middleware('throttle:change-password');
});

Route::middleware(['auth:sanctum', 'permission:'.RoleEnum::SUPERADMIN->value.','.RoleEnum::LEAGUE_ADMIN->value])->group(function (): void {
    Route::get('/my-leagues', [MyLeagueController::class, 'index']);

    // Recursos de una liga (torneos, árbitros, canchas, equipos...): siempre anidados bajo
    // /leagues/{league}/... y filtrados por esa liga, nunca por un league_id del body.
    Route::middleware('league.access')->prefix('/leagues/{league}')->group(function (): void {
        Route::get('/referees', [RefereeController::class, 'index']);
        Route::post('/referees', [RefereeController::class, 'store']);
        Route::get('/referees/lookup', [RefereeController::class, 'lookup'])->middleware('throttle:referee-lookup');
        Route::post('/referees/attach', [RefereeController::class, 'attach'])->middleware('throttle:referee-lookup');
        Route::put('/referees/{user}', [RefereeController::class, 'update'])->whereNumber('user');
        Route::patch('/referees/{user}/status', [RefereeController::class, 'updateStatus'])->whereNumber('user');
        Route::delete('/referees/{user}', [RefereeController::class, 'destroy'])->whereNumber('user');
        Route::post('/referees/{user}/resend-invitation', [RefereeController::class, 'resendInvitation'])
            ->whereNumber('user')->middleware('throttle:resend-invitation');

        // Fuera de scopeBindings: el jugador no es hijo de la liga; el servicio comprueba que tenga roster en ella.
        Route::get('/players', [PlayerController::class, 'index']);
        Route::get('/players/{player}', [PlayerController::class, 'show'])->whereNumber('player');
        Route::post('/players/{player}/ban', [PlayerController::class, 'ban'])->whereNumber('player');
        Route::post('/players/{player}/unban', [PlayerController::class, 'unban'])->whereNumber('player');
        Route::patch('/players/{player}/gender', [PlayerController::class, 'updateGender'])->whereNumber('player');

        Route::scopeBindings()->group(function (): void {
            Route::get('/tournaments', [TournamentController::class, 'index']);
            Route::post('/tournaments', [TournamentController::class, 'store']);
            Route::get('/tournaments/{tournament}', [TournamentController::class, 'show']);
            Route::put('/tournaments/{tournament}', [TournamentController::class, 'update']);
            Route::patch('/tournaments/{tournament}/status', [TournamentController::class, 'updateStatus']);
            Route::delete('/tournaments/{tournament}', [TournamentController::class, 'destroy']);

            // assignments y random-assignment antes de /{group}: si no, se tomarían como el id de un grupo.
            Route::get('/tournaments/{tournament}/groups', [GroupController::class, 'index']);
            Route::post('/tournaments/{tournament}/groups', [GroupController::class, 'store']);
            Route::put('/tournaments/{tournament}/groups/assignments', [GroupController::class, 'assign']);
            Route::post('/tournaments/{tournament}/groups/random-assignment', [GroupController::class, 'randomAssignment']);
            Route::get('/tournaments/{tournament}/groups/{group}', [GroupController::class, 'show'])->whereNumber('group');
            Route::patch('/tournaments/{tournament}/groups/{group}', [GroupController::class, 'update'])->whereNumber('group');
            Route::delete('/tournaments/{tournament}/groups/{group}', [GroupController::class, 'destroy'])->whereNumber('group');

            // generate y schedule son rutas fijas: no hay /matches/{match}.
            Route::get('/tournaments/{tournament}/matches', [MatchController::class, 'index']);
            Route::get('/tournaments/{tournament}/matches/schedule', [MatchController::class, 'schedule']);
            Route::post('/tournaments/{tournament}/matches/generate', [MatchController::class, 'generate']);
            Route::delete('/tournaments/{tournament}/matches', [MatchController::class, 'destroy']);

            // available-teams antes de /{registration}: si no, se tomaría como el id de una inscripción.
            Route::get('/tournaments/{tournament}/registrations', [RegistrationController::class, 'index']);
            Route::post('/tournaments/{tournament}/registrations', [RegistrationController::class, 'store']);
            Route::get('/tournaments/{tournament}/registrations/available-teams', [RegistrationController::class, 'availableTeams']);
            Route::delete('/tournaments/{tournament}/registrations/{registration}', [RegistrationController::class, 'destroy'])
                ->whereNumber('registration');

            // Roster de una inscripción: solo consulta y moderación; las solicitudes son de la app.
            Route::get('/tournaments/{tournament}/registrations/{registration}/roster', [RosterController::class, 'index'])
                ->whereNumber('registration');
            Route::patch('/tournaments/{tournament}/registrations/{registration}/roster/{roster}/remove', [RosterController::class, 'remove'])
                ->whereNumber(['registration', 'roster']);
            Route::post('/tournaments/{tournament}/registrations/{registration}/roster/{roster}/rejoin', [RosterController::class, 'authorizeRejoin'])
                ->whereNumber(['registration', 'roster']);
            Route::delete('/tournaments/{tournament}/registrations/{registration}/roster/{roster}/rejoin', [RosterController::class, 'revokeRejoin'])
                ->whereNumber(['registration', 'roster']);

            Route::get('/fields', [FieldController::class, 'index']);
            Route::post('/fields', [FieldController::class, 'store']);
            Route::get('/fields/{field}', [FieldController::class, 'show']);
            Route::put('/fields/{field}', [FieldController::class, 'update']);
            Route::patch('/fields/{field}/status', [FieldController::class, 'updateStatus']);
            Route::delete('/fields/{field}', [FieldController::class, 'destroy']);

            Route::get('/teams', [TeamController::class, 'index']);
            Route::post('/teams', [TeamController::class, 'store']);
            // Antes de /teams/{team}: si no, "manager-lookup" se tomaría como el id de un equipo.
            Route::get('/teams/managers', [TeamController::class, 'managers']);
            Route::get('/teams/manager-lookup', [TeamController::class, 'managerLookup'])->middleware('throttle:referee-lookup');
            Route::get('/teams/{team}', [TeamController::class, 'show']);
            Route::put('/teams/{team}', [TeamController::class, 'update']);
            Route::patch('/teams/{team}/status', [TeamController::class, 'updateStatus']);
            Route::delete('/teams/{team}', [TeamController::class, 'destroy']);
            Route::post('/teams/{team}/logo', [TeamController::class, 'uploadLogo']);
            Route::delete('/teams/{team}/logo', [TeamController::class, 'destroyLogo']);
            Route::put('/teams/{team}/manager', [TeamController::class, 'assignManager']);
            Route::patch('/teams/{team}/manager', [TeamController::class, 'updateManager']);
            Route::delete('/teams/{team}/manager', [TeamController::class, 'destroyManager']);
            Route::post('/teams/{team}/manager/resend-invitation', [TeamController::class, 'resendManagerInvitation'])
                ->middleware('throttle:resend-invitation');
        });
    });
});

Route::middleware(['auth:sanctum', 'permission:'.RoleEnum::SUPERADMIN->value])->group(function (): void {
    Route::get('/roles', [RoleController::class, 'index']);

    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::patch('/users/{id}/status', [UserController::class, 'updateStatus']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
    Route::post('/users/{id}/resend-invitation', [UserController::class, 'resendInvitation'])->middleware('throttle:resend-invitation');

    Route::get('/leagues', [LeagueController::class, 'index']);
    Route::post('/leagues', [LeagueController::class, 'store']);
    Route::get('/leagues/{id}', [LeagueController::class, 'show']);
    Route::put('/leagues/{id}', [LeagueController::class, 'update']);
    Route::delete('/leagues/{id}', [LeagueController::class, 'destroy']);
});
