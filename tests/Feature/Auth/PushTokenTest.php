<?php

namespace Tests\Feature\Auth;

use App\Models\PushToken;
use App\Models\User;
use App\Notifications\Channels\ExpoPushChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tokens de notificaciones push de la app y el canal que manda los avisos por Expo.
 */
class PushTokenTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'ExponentPushToken[abc123]';

    public function test_registers_the_token_of_the_phone(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/push-tokens', ['token' => self::TOKEN, 'platform' => 'ios'])
            ->assertNoContent();

        $this->assertDatabaseHas('push_tokens', ['user_id' => $user->id, 'token' => self::TOKEN, 'platform' => 'ios']);
    }

    public function test_the_token_moves_to_the_account_that_signs_in_on_that_phone(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        PushToken::query()->create(['user_id' => $first->id, 'token' => self::TOKEN]);

        $this->actingAs($second)->postJson('/api/push-tokens', ['token' => self::TOKEN])->assertNoContent();

        $this->assertSame(1, PushToken::query()->count());
        $this->assertDatabaseHas('push_tokens', ['user_id' => $second->id, 'token' => self::TOKEN]);
    }

    public function test_removes_the_token_when_signing_out(): void
    {
        $user = User::factory()->create();
        PushToken::query()->create(['user_id' => $user->id, 'token' => self::TOKEN]);

        $this->actingAs($user)->deleteJson('/api/push-tokens', ['token' => self::TOKEN])->assertNoContent();

        $this->assertDatabaseCount('push_tokens', 0);
    }

    public function test_rejects_tokens_that_are_not_from_expo_and_requires_a_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/push-tokens', ['token' => 'cualquier-cosa'])->assertStatus(422);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/push-tokens', ['token' => self::TOKEN])->assertUnauthorized();
    }

    public function test_the_channel_sends_to_every_phone_and_forgets_uninstalled_ones(): void
    {
        $user = User::factory()->create();
        PushToken::query()->create(['user_id' => $user->id, 'token' => 'ExponentPushToken[uno]']);
        PushToken::query()->create(['user_id' => $user->id, 'token' => 'ExponentPushToken[dos]']);

        Http::fake([ExpoPushChannel::ENDPOINT => Http::response(['data' => [
            ['status' => 'ok', 'id' => 'a'],
            ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']],
        ]])]);

        (new ExpoPushChannel)->send($user, new FakePushNotification);

        Http::assertSent(function (Request $request): bool {
            $messages = $request->data();

            return count($messages) === 2
                && $messages[0]['to'] === 'ExponentPushToken[uno]'
                && $messages[0]['title'] === 'Hola'
                && $messages[1]['data'] === ['type' => 'test'];
        });
        $this->assertDatabaseHas('push_tokens', ['token' => 'ExponentPushToken[uno]']);
        $this->assertDatabaseMissing('push_tokens', ['token' => 'ExponentPushToken[dos]']);
    }

    public function test_a_failure_at_expo_does_not_break_the_request(): void
    {
        $user = User::factory()->create();
        PushToken::query()->create(['user_id' => $user->id, 'token' => self::TOKEN]);
        Http::fake([ExpoPushChannel::ENDPOINT => Http::response('caído', 503)]);

        (new ExpoPushChannel)->send($user, new FakePushNotification);

        $this->assertDatabaseHas('push_tokens', ['token' => self::TOKEN]);
    }

    public function test_does_not_call_expo_without_tokens(): void
    {
        Http::fake();

        (new ExpoPushChannel)->send(User::factory()->create(), new FakePushNotification);

        Http::assertNothingSent();
    }
}

class FakePushNotification extends Notification
{
    /**
     * @return array{title: string, body: string, data: array<string, mixed>}
     */
    public function toExpoPush(User $notifiable): array
    {
        return ['title' => 'Hola', 'body' => 'Prueba', 'data' => ['type' => 'test']];
    }
}
