<?php

namespace App\Notifications\Channels;

use App\Models\PushToken;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Canal de notificaciones push por el servicio de Expo, que las reparte a APNs (iPhone) y FCM (Android).
 *
 * La notificación implementa `toExpoPush()` y devuelve título, cuerpo y datos. Se manda a todos los
 * celulares del usuario. Si Expo dice que un token ya no existe (desinstaló la app), se borra.
 * Si Expo no responde, solo se registra en el log: la acción que la disparó no debe fallar por esto.
 */
class ExpoPushChannel
{
    public const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! method_exists($notification, 'toExpoPush')) {
            return;
        }

        /** @var list<string> $tokens */
        $tokens = $notifiable->pushTokens()->pluck('token')->all();
        if ($tokens === []) {
            return;
        }

        /** @var array{title: string, body: string, data?: array<string, mixed>} $message */
        $message = $notification->toExpoPush($notifiable);

        $messages = array_map(fn (string $token): array => [
            'to' => $token,
            'title' => $message['title'],
            'body' => $message['body'],
            'data' => $message['data'] ?? (object) [],
            'sound' => 'default',
        ], $tokens);

        try {
            $response = Http::acceptJson()->timeout(5)->post(self::ENDPOINT, $messages);
        } catch (Throwable $e) {
            Log::warning('No se pudo mandar la notificación push', ['error' => $e->getMessage()]);

            return;
        }

        if (! $response->successful()) {
            Log::warning('Expo rechazó la notificación push', ['status' => $response->status(), 'body' => $response->body()]);

            return;
        }

        // Expo responde un ticket por mensaje, en el mismo orden.
        $tickets = $response->json('data');
        if (! is_array($tickets)) {
            return;
        }

        $gone = [];
        foreach (array_values($tickets) as $i => $ticket) {
            $details = is_array($ticket) ? ($ticket['details'] ?? null) : null;
            if (is_array($details) && ($details['error'] ?? null) === 'DeviceNotRegistered' && isset($tokens[$i])) {
                $gone[] = $tokens[$i];
            }
        }
        if ($gone !== []) {
            PushToken::query()->whereIn('token', $gone)->delete();
        }
    }
}
