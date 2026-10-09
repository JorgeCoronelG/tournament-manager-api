<?php

use App\Core\Enum\Role as RoleEnum;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LeagueController;
use App\Http\Controllers\Api\MyLeagueController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\RefereeController;
use App\Http\Controllers\Api\RoleController;
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

        Route::scopeBindings()->group(function (): void {
            Route::get('/tournaments', [TournamentController::class, 'index']);
            Route::post('/tournaments', [TournamentController::class, 'store']);
            Route::get('/tournaments/{tournament}', [TournamentController::class, 'show']);
            Route::put('/tournaments/{tournament}', [TournamentController::class, 'update']);
            Route::patch('/tournaments/{tournament}/status', [TournamentController::class, 'updateStatus']);
            Route::delete('/tournaments/{tournament}', [TournamentController::class, 'destroy']);
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
