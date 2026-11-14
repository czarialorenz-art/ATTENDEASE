<?php

namespace App\Policies;

use App\Models\AttendanceCorrection;
use App\Models\User;

class AttendanceCorrectionPolicy
{
    /** FR-ATT-18: only the class's teacher approves or rejects. */
    public function decide(User $user, AttendanceCorrection $correction): bool
    {
        $session = $correction->session();

        return $session && (int) $session->schoolClass->teacher_id === (int) $user->id;
    }
}
