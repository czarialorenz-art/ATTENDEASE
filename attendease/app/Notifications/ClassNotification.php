<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * FR-NOTIF-02: class events go to the in-app bell (database) and by email.
 *
 * The database copy is written synchronously so the bell updates even without a queue worker;
 * the email copy is queued so a mail failure never fails the request (FR-MAIL intro).
 */
abstract class ClassNotification extends Notification implements ShouldQueue
{
    use Queueable;

    abstract protected function subject(): string;

    abstract protected function message(): string;

    abstract protected function url(): string;

    /** @return list<string> */
    protected function lines(): array
    {
        return [$this->message()];
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subject())
            ->greeting('Hi '.$notifiable->name.',');

        foreach ($this->lines() as $line) {
            $mail->line($line);
        }

        return $mail->action('Open in '.config('app.name'), $this->url());
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->subject(),
            'message' => $this->message(),
            'url' => $this->url(),
        ];
    }
}
