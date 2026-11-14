<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * FR-MAIL-02: security alerts, email only.
 */
class SecurityAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public const NEW_SIGN_IN = 'new_sign_in';
    public const EMAIL_CHANGED = 'email_changed';
    public const PASSWORD_CHANGED = 'password_changed';

    /**
     * @param  array{time: string, ip: string, browser: string}  $context
     */
    public function __construct(
        public string $type,
        public array $context,
        public ?string $name = null,
        public ?string $newEmail = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        [$subject, $line] = match ($this->type) {
            self::NEW_SIGN_IN => ['New sign-in to your account', 'Your account was just signed in to from a device or browser we have not seen before.'],
            self::EMAIL_CHANGED => ['Your email was changed', 'The email address on your account was changed to '.$this->newEmail.'.'],
            default => ['Your password was changed', 'The password for your account was just changed.'],
        };

        $name = $this->name ?? ($notifiable->name ?? null);

        return (new MailMessage)
            ->subject($subject)
            ->greeting($name ? "Hi {$name}," : 'Hello,')
            ->line($line)
            ->line('**Time:** '.$this->context['time'])
            ->line('**IP address:** '.$this->context['ip'])
            ->line('**Browser:** '.$this->context['browser'])
            ->line("If this wasn't you, reset your password.")
            ->action('Reset your password', route('password.request'));
    }
}
