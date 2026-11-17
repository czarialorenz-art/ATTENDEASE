<?php

namespace App\Policies;

use App\Models\Enrollment;
use App\Models\User;

class EnrollmentPolicy
{
    /** FR-CLS-05/06: only the class's teacher approves, rejects or removes. */
    public function decide(User $user, Enrollment $enrollment): bool
    {
        return $user->teaches($enrollment->schoolClass);
    }
}
