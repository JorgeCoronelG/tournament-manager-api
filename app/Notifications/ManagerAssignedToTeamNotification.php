<?php

namespace App\Notifications;

use App\Models\Team;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ManagerAssignedToTeamNotification extends Notification
{
    public function __construct(protected Team $team) {}

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
        $league = $this->team->league;

        return (new MailMessage)
            ->subject("Fuiste asignado como delegado de {$this->team->name}")
            ->greeting("¡Hola, {$notifiable->first_name}!")
            ->line("Fuiste asignado como delegado del equipo {$this->team->name}"
                .($league ? " de la liga {$league->name}" : '').' en '.config()->string('app.name').'.')
            ->line('No necesitas hacer nada: ya puedes gestionar ese equipo en la aplicación móvil.');
    }
}
