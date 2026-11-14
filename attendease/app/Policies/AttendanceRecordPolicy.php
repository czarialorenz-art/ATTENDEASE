<?php

namespace App\Policies;

use App\Models\AttendanceRecord;
use App\Models\User;

class AttendanceRecordPolicy
{
    /**
     * FR-ATT-18: a student may ask to correct their own record, while still an approved
     * member of the class, and not for an archived session.
     */
    public function requestCorrection(User $user, AttendanceRecord $record): bool
    {
        if ((int) $record->student_id !== (int) $user->id) {
            return false;
        }

        $session = $record->attendanceSession; // null when the session is archived
        if (! $session) {
            return false;
        }

        return ! $user->teaches($session->schoolClass) && $user->isApprovedIn($session->schoolClass);
    }
}
