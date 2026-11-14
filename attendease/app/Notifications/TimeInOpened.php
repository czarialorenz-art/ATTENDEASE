<?php

namespace App\Notifications;

use App\Models\AttendanceSession;

/**
 * FR-ATT-17: sent to every approved student when the teacher opens Time In
 * (the first time only), so they know to scan now.
 */
class TimeInOpened extends ClassNotification
{
    public function __construct(public AttendanceSession $session) {}

    /** End of the grace period: scans until then are Present (FR-ATT-09). */
    private function presentUntil(): string
    {
        return $this->session->time_in_opened_at->copy()
            ->addMinutes($this->session->grace_minutes)
            ->timezone(config('app.timezone'))
            ->format('g:i A');
    }

    protected function subject(): string
    {
        return 'Time In is open: '.$this->session->schoolClass->label();
    }

    protected function message(): string
    {
        $title = $this->session->title ? ' ('.$this->session->title.')' : '';

        return 'Time In is open for '.$this->session->schoolClass->label().$title.'. Scan the QR code on your teacher\'s screen'
            .($this->session->grace_minutes > 0 ? ' by '.$this->presentUntil().' to be marked Present.' : ' now to be marked Present.');
    }

    protected function lines(): array
    {
        return [
            $this->message(),
            'Scans after that time are marked Late.',
        ];
    }

    protected function url(): string
    {
        return route('scanner');
    }
}
