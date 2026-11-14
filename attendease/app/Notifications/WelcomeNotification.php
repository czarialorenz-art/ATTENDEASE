<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** FR-MAIL-01 */
class WelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Welcome to '.config('app.name'))
            ->greeting('Welcome, '.$notifiable->name.'!')
            ->line('Your '.config('app.name').' account at '.config('app.school_name').' is ready.')
            ->line('Create a class to teach, or join one with the code your teacher shares.')
            ->action('Go to your dashboard', route('dashboard'));
    }
}
