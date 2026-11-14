<?php

namespace App\Notifications;

use App\Models\Enrollment;

class JoinRequestDecided extends ClassNotification
{
    public function __construct(public Enrollment $enrollment) {}

    private function approved(): bool
    {
        return $this->enrollment->status === Enrollment::APPROVED;
    }

    protected function subject(): string
    {
        return 'Join request '.($this->approved() ? 'approved' : 'rejected').': '.$this->enrollment->schoolClass->label();
    }

    protected function message(): string
    {
        return $this->approved()
            ? 'You are now enrolled in '.$this->enrollment->schoolClass->label().'.'
            : 'Your request to join '.$this->enrollment->schoolClass->label().' was rejected. You may ask again later.';
    }

    protected function url(): string
    {
        return $this->approved()
            ? route('classes.show', $this->enrollment->class_id)
            : route('dashboard');
    }
}
