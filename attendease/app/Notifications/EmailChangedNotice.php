<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** FR-AUTH-16: notice sent to the new address. */
class EmailChangedNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('This is now your '.config('app.name').' email')
            ->greeting('Hi '.$notifiable->name.',')
            ->line('This address is now the sign-in email for your '.config('app.name').' account.')
            ->action('Go to your dashboard', route('dashboard'));
    }
}
