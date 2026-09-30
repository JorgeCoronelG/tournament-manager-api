<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountInvitationNotification extends Notification
{
    public function __construct(protected string $code, protected int $expiresInHours) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * @param  User  $notifiable
     */
    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config()->string('app.frontend_url'), '/')
            .'/activar-cuenta?email='.urlencode($notifiable->email)
            .'&code='.$this->code;

        return (new MailMessage)
            ->subject('Activa tu cuenta')
            ->greeting("¡Hola, {$notifiable->first_name}!")
            ->line('Se creó una cuenta para ti en '.config()->string('app.name').'.')
            ->action('Activar mi cuenta', $url)
            ->line("Si el botón no funciona, usa este código de verificación: {$this->code}")
            ->line("Este código vence en {$this->expiresInHours} horas.")
            ->line('Si no esperabas este correo, puedes ignorarlo.');
    }
}
