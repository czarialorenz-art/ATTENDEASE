<?php

namespace Tests;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /** Freeze time at a Manila moment aligned to a 30-second QR window. */
    protected function freezeManila(string $datetime = '2026-11-12 08:00:00'): Carbon
    {
        $now = Carbon::parse($datetime, 'Asia/Manila');
        $this->travelTo($now);

        return $now;
    }

    protected function makeClass(?User $teacher = null, array $attrs = []): SchoolClass
    {
        $teacher ??= User::factory()->teacher()->create();

        // Roles are enforced (FR-ACL-03): whoever a test makes teach a class is a Teacher.
        if (! $teacher->isTeacher()) {
            $teacher->forceFill(['role' => User::TEACHER])->save();
        }

        return SchoolClass::factory()->for($teacher, 'teacher')->create($attrs);
    }

    protected function enroll(SchoolClass $class, ?User $student = null, string $status = Enrollment::APPROVED): User
    {
        $student ??= User::factory()->create();

        Enrollment::factory()->create([
            'class_id' => $class->id,
            'student_id' => $student->id,
            'status' => $status,
            'decided_at' => $status === Enrollment::PENDING ? null : now(),
        ]);

        return $student;
    }
}
