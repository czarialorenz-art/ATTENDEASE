<?php

namespace App\Policies;

use App\Models\SchoolClass;
use App\Models\User;

/**
 * FR-ACL-01/02: roles are per class. The creator is the teacher; approved enrollees are students.
 */
class SchoolClassPolicy
{
    /** Teacher-only actions: students, sessions, assessments, review (all rows), report, schedule. */
    public function teach(User $user, SchoolClass $class): bool
    {
        return $user->teaches($class);
    }

    public function update(User $user, SchoolClass $class): bool
    {
        return $user->teaches($class);
    }

    /** Student-only actions: scan, submit, own records. */
    public function attend(User $user, SchoolClass $class): bool
    {
        return ! $user->teaches($class) && $user->isApprovedIn($class);
    }

    /** Either side may open the class page; each sees their own view. */
    public function view(User $user, SchoolClass $class): bool
    {
        return $this->teach($user, $class) || $this->attend($user, $class);
    }
}
