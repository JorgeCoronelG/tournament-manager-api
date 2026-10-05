<?php

use App\Core\Enum\Role as RoleEnum;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LeagueController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\RoleController;
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
