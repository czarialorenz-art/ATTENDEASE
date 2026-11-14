<?php

namespace App\Notifications;

use App\Models\Enrollment;

class JoinRequestReceived extends ClassNotification
{
    public function __construct(public Enrollment $enrollment) {}

    protected function subject(): string
    {
        return 'New join request: '.$this->enrollment->schoolClass->label();
    }

    protected function message(): string
    {
        return $this->enrollment->student->name.' ('.$this->enrollment->student->id_number.') asked to join '
            .$this->enrollment->schoolClass->label().'.';
    }

    protected function url(): string
    {
        return route('classes.students', $this->enrollment->class_id);
    }
}
