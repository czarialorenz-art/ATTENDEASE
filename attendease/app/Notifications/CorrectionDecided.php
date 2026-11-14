<?php

namespace App\Notifications;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceSession;

/** FR-ATT-18: tells the student whether their correction request was approved or rejected. */
class CorrectionDecided extends ClassNotification
{
    public function __construct(public AttendanceCorrection $correction, public AttendanceSession $session) {}

    private function approved(): bool
    {
        return $this->correction->status === AttendanceCorrection::APPROVED;
    }

    protected function subject(): string
    {
        return 'Correction '.($this->approved() ? 'approved' : 'rejected').': '.$this->session->schoolClass->label();
    }

    protected function message(): string
    {
        $day = $this->session->date->format('M j, Y');

        return $this->approved()
            ? "Your attendance for {$day} was changed to ".ucfirst($this->correction->requested_status).'.'
            : "Your request to change {$day} to ".ucfirst($this->correction->requested_status).' was rejected.'
                .($this->correction->teacher_note ? ' Note: '.$this->correction->teacher_note : '');
    }

    protected function lines(): array
    {
        $lines = [$this->message()];
        if ($this->approved() && $this->correction->teacher_note) {
            $lines[] = 'Note from your teacher: '.$this->correction->teacher_note;
        }

        return $lines;
    }

    protected function url(): string
    {
        return route('attendance.history', ['class_id' => $this->session->class_id]);
    }
}
