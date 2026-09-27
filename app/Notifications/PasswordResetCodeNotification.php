<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetCodeNotification extends Notification
{
    public function __construct(protected string $code, protected int $expiresInMinutes) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Código para recuperar tu contraseña')
            ->greeting('¡Hola!')
            ->line('Recibimos una solicitud para restablecer tu contraseña.')
            ->line("Tu código de verificación es: {$this->code}")
            ->line("Este código vence en {$this->expiresInMinutes} minutos.")
            ->line('Si no solicitaste este cambio, puedes ignorar este correo.')
            ->salutation('Saludos,'."\n".config()->string('app.name'));
    }
}
