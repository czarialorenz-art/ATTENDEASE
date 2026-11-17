<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Enrollment;
use App\Models\ScanLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_fr_acl_01_every_route_requires_auth(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/classes/1')->assertRedirect('/login');
        $this->get('/scan?t=x')->assertRedirect('/login');
        $this->post('/scan')->assertRedirect('/login');
        $this->get('/notifications')->assertRedirect('/login');
    }

    /** FR-ACL-03: every account has one role; a new account (sign-up) is a Student. */
    public function test_fr_acl_03_accounts_have_a_role_and_default_to_student(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'role'));
        $this->assertSame(User::STUDENT, (new User)->role);
        $this->assertSame(User::STUDENT, User::factory()->create()->fresh()->role);
    }

    /** Acceptance: Teacher A gets 403 on every route for Teacher B's class. */
    public function test_fr_acl_02_teacher_gets_403_on_every_route_of_another_teachers_class(): void
    {
        $teacherA = User::factory()->create();
        $class = $this->makeClass(); // Teacher B's class
        $student = $this->enroll($class);
        $pending = Enrollment::factory()->create(['class_id' => $class->id]);
        $session = AttendanceSession::factory()->for($class, 'schoolClass')->create();
        $record = AttendanceRecord::factory()->create(['attendance_session_id' => $session->id, 'student_id' => $student->id]);
        $schedule = \App\Models\ClassSchedule::factory()->create(['class_id' => $class->id]);

        $this->actingAs($teacherA);

        $requests = [
            ['get', "/classes/{$class->id}"],
            ['get', "/classes/{$class->id}/edit"],
            ['put', "/classes/{$class->id}"],
            ['put', "/classes/{$class->id}/location"],
            ['post', "/classes/{$class->id}/schedules"],
            ['put', "/schedules/{$schedule->id}"],
            ['delete', "/schedules/{$schedule->id}"],
            ['get', "/classes/{$class->id}/students"],
            ['post', "/enrollments/{$pending->id}/approve"],
            ['post', "/enrollments/{$pending->id}/reject"],
            ['get', "/classes/{$class->id}/sessions"],
            ['post', "/classes/{$class->id}/sessions"],
            ['get', "/sessions/{$session->id}"],
            ['put', "/sessions/{$session->id}"],
            ['post', "/sessions/{$session->id}/stages/time_in/open"],
            ['post', "/sessions/{$session->id}/stages/time_in/close"],
            ['get', "/sessions/{$session->id}/live"],
            ['get', "/sessions/{$session->id}/live/state"],
            ['post', "/sessions/{$session->id}/records"],
            ['get', "/classes/{$class->id}/report"],
            ['get', "/classes/{$class->id}/report.pdf"],
        ];

        foreach ($requests as [$method, $uri]) {
            $this->{$method}($uri)->assertForbidden();
        }

        // Nothing changed.
        $this->assertSame(Enrollment::PENDING, $pending->fresh()->status);
        $this->assertNull($session->fresh()->time_in_opened_at);
    }

    public function test_fr_acl_01_students_cannot_use_teacher_actions(): void
    {
        $class = $this->makeClass();
        $student = $this->enroll($class);
        $session = AttendanceSession::factory()->for($class, 'schoolClass')->create();

        $this->actingAs($student);
        $this->get("/classes/{$class->id}/students")->assertForbidden();
        $this->get("/sessions/{$session->id}")->assertForbidden();
        $this->post("/sessions/{$session->id}/stages/time_in/open")->assertForbidden();
        $this->get("/classes/{$class->id}/report")->assertForbidden();
        $this->get("/classes/{$class->id}")->assertOk(); // their own student view
    }

    public function test_fr_acl_01_pending_rejected_or_removed_students_get_403(): void
    {
        $class = $this->makeClass();

        foreach ([Enrollment::PENDING, Enrollment::REJECTED, Enrollment::REMOVED] as $status) {
            $user = $this->enroll($class, null, $status);
            $this->actingAs($user)->get("/classes/{$class->id}")->assertForbidden();
        }

        $this->actingAs(User::factory()->create())->get("/classes/{$class->id}")->assertForbidden();
    }

    public function test_fr_acl_04_missing_resources_404_without_leaking(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/classes/999999')->assertNotFound();
        $this->get('/sessions/999999')->assertNotFound();
        $this->get('/sessions/999999/live')->assertNotFound();
    }
}
