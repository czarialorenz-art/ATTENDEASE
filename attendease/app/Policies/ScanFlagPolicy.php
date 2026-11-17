<?php

namespace App\Policies;

use App\Models\ScanFlag;
use App\Models\User;

class ScanFlagPolicy
{
    /** FR-ATT-11: only the class's teacher clears flags, and not on an archived session (FR-ATT-16). */
    public function clear(User $user, ScanFlag $flag): bool
    {
        $session = $flag->scanLog->record->attendanceSession()->withTrashed()->first();

        return $session
            && ! $session->isArchived()
            && $user->teaches($session->schoolClass);
    }
}
