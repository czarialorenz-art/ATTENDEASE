<?php

namespace App\Notifications;

use App\Models\Enrollment;

class RemovedFromClass extends ClassNotification
{
    public function __construct(public Enrollment $enrollment) {}

    protected function subject(): string
    {
        return 'Removed from '.$this->enrollment->schoolClass->label();
    }

    protected function message(): string
    {
        return 'Your teacher removed you from '.$this->enrollment->schoolClass->label().'.';
    }

    protected function url(): string
    {
        return route('dashboard');
    }
}
