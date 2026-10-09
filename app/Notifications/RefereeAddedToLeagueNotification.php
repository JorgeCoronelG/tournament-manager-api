<?php

namespace App\Notifications;

use App\Models\League;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RefereeAddedToLeagueNotification extends Notification
{
    public function __construct(protected League $league) {}

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
        return (new MailMessage)
            ->subject("Fuiste agregado a la liga {$this->league->name}")
            ->greeting("¡Hola, {$notifiable->first_name}!")
            ->line("Fuiste agregado como árbitro a la liga {$this->league->name} en ".config()->string('app.name').'.')
            ->line('No necesitas hacer nada: ya puedes recibir asignaciones de esa liga.');
    }
}
