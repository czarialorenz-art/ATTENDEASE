<?php

namespace App\Policies;

use App\Models\AttendanceSession;
use App\Models\User;

class AttendanceSessionPolicy
{
    /** View, edit, open/close stages, live QR, manual records. */
    public function manage(User $user, AttendanceSession $session): bool
    {
        return (int) $session->schoolClass->teacher_id === (int) $user->id;
    }
}
