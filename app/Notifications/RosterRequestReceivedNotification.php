<?php

namespace App\Notifications;

use App\Models\Registration;
use App\Models\Tournament;
use App\Models\User;
use App\Notifications\Channels\ExpoPushChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso al jugador de que un delegado lo invitó a su plantilla. La respuesta se da en la app.
 */
class RosterRequestReceivedNotification extends Notification
{
    public function __construct(protected Registration $registration, protected Tournament $tournament) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', ExpoPushChannel::class];
    }

    /**
     * Aviso en el celular; al tocarlo, la app abre la pantalla donde se acepta o se rechaza.
     *
     * @return array{title: string, body: string, data: array<string, mixed>}
     */
    public function toExpoPush(User $notifiable): array
    {
        $team = $this->registration->team;
        $teamName = $team === null ? 'Un equipo' : $team->name;

        return [
            'title' => "{$teamName} te invitó a su plantilla",
            'body' => "Torneo {$this->tournament->name}. Toca para aceptar o rechazar.",
            'data' => ['type' => 'roster_request', 'registration_id' => $this->registration->id],
        ];
    }

    /**
     * @param  User  $notifiable
     */
    public function toMail(object $notifiable): MailMessage
    {
        $team = $this->registration->team;
        $teamName = $team === null ? 'un equipo' : $team->name;

        return (new MailMessage)
            ->subject("{$teamName} te invitó a su plantilla")
            ->greeting("¡Hola, {$notifiable->first_name}!")
            ->line("El delegado de {$teamName} te invitó a su plantilla para el torneo {$this->tournament->name}.")
            ->line('Abre la aplicación de '.config()->string('app.name').' para aceptar o rechazar la solicitud.');
    }
}
