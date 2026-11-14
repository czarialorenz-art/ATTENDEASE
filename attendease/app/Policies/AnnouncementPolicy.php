<?php

namespace App\Policies;

use App\Models\Announcement;
use App\Models\User;

class AnnouncementPolicy
{
    /** FR-ANN-01: only the class's teacher edits or deletes its announcements. */
    public function manage(User $user, Announcement $announcement): bool
    {
        return $user->teaches($announcement->schoolClass);
    }
}
