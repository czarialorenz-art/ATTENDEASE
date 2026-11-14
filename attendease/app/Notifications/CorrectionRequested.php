<?php

namespace App\Notifications;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceSession;

/** FR-ATT-18: tells the teacher a student asked for an attendance correction. */
class CorrectionRequested extends ClassNotification
{
    public function __construct(public AttendanceCorrection $correction, public AttendanceSession $session) {}

    protected function subject(): string
    {
        return 'Correction request: '.$this->session->schoolClass->label();
    }

    protected function message(): string
    {
        return $this->correction->student->name.' asked to change '.$this->session->date->format('M j, Y')
            .' from '.ucfirst($this->correction->current_status).' to '.ucfirst($this->correction->requested_status).'.';
    }

    protected function lines(): array
    {
        return [$this->message(), 'Reason: '.$this->correction->reason];
    }

    protected function url(): string
    {
        return route('corrections.index', $this->session->class_id);
    }
}
