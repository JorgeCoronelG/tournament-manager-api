<?php

namespace App\Notifications;

use App\Models\Registration;
use App\Models\Tournament;
use App\Models\User;
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
        return ['mail'];
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
