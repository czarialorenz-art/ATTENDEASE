<?php

namespace App\Notifications;

use App\Models\AttendanceSession;

class AbsenceAlert extends ClassNotification
{
    public function __construct(public AttendanceSession $session) {}

    protected function subject(): string
    {
        return 'Marked absent: '.$this->session->schoolClass->label();
    }

    protected function message(): string
    {
        return 'You were marked Absent in '.$this->session->schoolClass->label().' on '
            .$this->session->date->format('M j, Y').($this->session->title ? ' ('.$this->session->title.')' : '').'.';
    }

    protected function url(): string
    {
        return route('attendance.history', ['class_id' => $this->session->class_id]);
    }
}
