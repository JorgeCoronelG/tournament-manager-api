<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', fn () => response()->json(['message' => 'Hello world!']));

// Escudos de equipos por Laravel (no por /storage, que el servidor entrega directo) para que lleven los headers CORS.
Route::get('/media/{path}', function (string $path) {
    $disk = Storage::disk('public');

    if (! str_starts_with($path, 'teams/logos/') || str_contains($path, '..') || ! $disk->exists($path)) {
        abort(404);
    }

    return $disk->response($path, null, ['Cache-Control' => 'public, max-age=86400', 'Vary' => 'Origin']);
})->where('path', '.*');
