<?php

namespace Tests\Feature\Core;

use App\Core\Enum\Message;
use App\Exceptions\CustomErrorException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ExceptionHandlerTest extends TestCase
{
    public function test_unknown_route_returns_404_json(): void
    {
        $this->getJson('/api/no-existe')
            ->assertNotFound()
            ->assertExactJson(['code' => 404, 'error' => Message::NOT_FOUND_HTTP_EXCEPTION]);
    }

    public function test_model_not_found_returns_404_json(): void
    {
        Route::get('/_test/model-not-found', fn () => throw new ModelNotFoundException);

        $this->getJson('/_test/model-not-found')
            ->assertNotFound()
            ->assertExactJson(['code' => 404, 'error' => Message::MODEL_NOT_FOUND_EXCEPTION]);
    }

    public function test_custom_error_exception_uses_its_code_and_message(): void
    {
        Route::get('/_test/custom', fn () => throw new CustomErrorException('boom', 400));

        $this->getJson('/_test/custom')
            ->assertStatus(400)
            ->assertExactJson(['code' => 400, 'error' => 'boom']);
    }

    public function test_validation_error_returns_422_with_field_errors(): void
    {
        Route::post('/_test/validate', fn (Request $r) => $r->validate(['name' => 'required']));

        $this->postJson('/_test/validate', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 422)
            ->assertJsonStructure(['code', 'error' => ['name']]);
    }

    public function test_unauthenticated_returns_401_json(): void
    {
        $this->getJson('/api/user')
            ->assertUnauthorized()
            ->assertExactJson(['code' => 401, 'error' => Message::AUTHENTICATION_EXCEPTION]);
    }

    public function test_unexpected_exception_is_hidden_when_debug_is_off(): void
    {
        config(['app.debug' => false]);
        Route::get('/_test/boom', fn () => throw new \RuntimeException('secreto'));

        $this->getJson('/_test/boom')
            ->assertStatus(500)
            ->assertExactJson(['code' => 500, 'error' => Message::INTERNAL_SERVER_ERROR]);
    }

    public function test_unexpected_exception_message_leaks_when_debug_is_on(): void
    {
        config(['app.debug' => true]);
        Route::get('/_test/boom', fn () => throw new \RuntimeException('secreto'));

        $this->getJson('/_test/boom')
            ->assertStatus(500)
            ->assertJsonPath('error', 'secreto');
    }

    public function test_login_rate_limit_returns_429_per_email_and_ip(): void
    {
        // Misma definición que la recomendada en el README.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($request->input('email').'|'.$request->ip()));
        Route::post('/_test/login', fn () => response()->json(['code' => 401, 'error' => 'Credenciales inválidas.'], 401))
            ->middleware('throttle:login');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/_test/login', ['email' => 'a@example.com'])->assertUnauthorized();
        }

        $this->postJson('/_test/login', ['email' => 'a@example.com'])
            ->assertStatus(429)
            ->assertExactJson(['code' => 429, 'error' => Message::THROTTLE_REQUESTS_EXCEPTION])
            ->assertHeader('Retry-After');

        // Otro correo desde la misma IP no queda bloqueado.
        $this->postJson('/_test/login', ['email' => 'b@example.com'])->assertUnauthorized();
    }
}
