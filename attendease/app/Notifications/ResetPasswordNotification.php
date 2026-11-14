<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/** FR-AUTH-10: queued reset link, rendered with the shared mail layout. */
class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;
}
