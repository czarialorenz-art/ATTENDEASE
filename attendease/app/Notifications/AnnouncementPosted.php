<?php

namespace App\Notifications;

use App\Models\Announcement;

/** FR-ANN-02: sent to every approved student when the teacher posts an announcement or cancellation. */
class AnnouncementPosted extends ClassNotification
{
    public function __construct(public Announcement $announcement) {}

    protected function subject(): string
    {
        $class = $this->announcement->schoolClass->label();

        return $this->announcement->isCancellation()
            ? "Class cancelled: {$class} on ".$this->announcement->cancelled_date->format('D, M j')
            : "{$class}: {$this->announcement->title}";
    }

    protected function message(): string
    {
        $a = $this->announcement;

        if ($a->isCancellation()) {
            return $a->schoolClass->label().' will not meet on '.$a->cancelled_date->format('l, F j, Y').'.'
                .($a->body ? ' '.$a->body : '');
        }

        return $a->title.': '.\Illuminate\Support\Str::limit((string) $a->body, 160);
    }

    protected function lines(): array
    {
        $a = $this->announcement;

        return $a->isCancellation()
            ? array_values(array_filter([
                $a->schoolClass->label().' will not meet on '.$a->cancelled_date->format('l, F j, Y').'.',
                $a->body ? 'Reason: '.$a->body : null,
            ]))
            : [$a->title, (string) $a->body];
    }

    protected function url(): string
    {
        return route('classes.show', $this->announcement->class_id).'#announcements';
    }
}
