<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\QrTokenService;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** FR-LOG-02/03: teachers' and students' actions in the Activity Log, with role and search filters. */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function last(string $event): Activity
    {
        return Activity::where('event', $event)->latest('id')->firstOrFail();
    }

    public function test_teacher_actions_are_logged(): void
    {
        $this->freezeManila('2026-11-12 08:00:00');
        $teacher = User::factory()->teacher()->create(['name' => 'Bryan Timbreza', 'id_number' => 'T-1']);

        // Class created and edited.
        $this->actingAs($teacher)->post('/classes', [
            'subject' => 'CAPSTONE 2', 'year_level' => '4th Year', 'section' => 'A', 'school_term' => '2026-2027',
            'semester' => '1st', 'semester_start' => '2026-08-01', 'semester_end' => '2026-12-15',
        ]);
        $class = $teacher->taughtClasses()->sole();
        $this->assertStringStartsWith('Created class CAPSTONE 2 · A (2026-2027, 1st sem) · code ', $this->last(Audit::CLASS_CREATED)->description);
        $this->assertSame(User::TEACHER, $this->last(Audit::CLASS_CREATED)->getProperty('causer_role'));

        $this->actingAs($teacher)->put("/classes/{$class->id}", [
            'subject' => 'CAPSTONE 2', 'year_level' => '4th Year', 'section' => 'A & B', 'school_term' => '2026-2027',
            'semester' => '1st', 'semester_start' => '2026-08-01', 'semester_end' => '2026-12-15',
        ]);
        $this->assertSame('CAPSTONE 2 · A & B: Section: A → A & B', $this->last(Audit::CLASS_UPDATED)->description);
        $this->actingAs($teacher)->put("/classes/{$class->id}/location", ['room' => 'Tesda Lab']);
        $this->assertSame('CAPSTONE 2 · A & B classroom location: Room: — → Tesda Lab', $this->last(Audit::CLASS_UPDATED)->description);

        // Schedules.
        $this->actingAs($teacher)->post("/classes/{$class->id}/schedules", ['day_of_week' => 4, 'start_time' => '08:00', 'end_time' => '09:30']);
        $this->assertSame('CAPSTONE 2 · A & B: added Thursday 8:00 AM – 9:30 AM', $this->last(Audit::SCHEDULE_ADDED)->description);
        $schedule = $class->schedules()->sole();
        $this->actingAs($teacher)->put("/schedules/{$schedule->id}", ['day_of_week' => 4, 'start_time' => '08:00', 'end_time' => '10:00']);
        $this->assertSame('CAPSTONE 2 · A & B: Thursday 8:00 AM – 9:30 AM → Thursday 8:00 AM – 10:00 AM', $this->last(Audit::SCHEDULE_UPDATED)->description);
        $this->actingAs($teacher)->delete("/schedules/{$schedule->id}");
        $this->assertSame('CAPSTONE 2 · A & B: removed Thursday 8:00 AM – 10:00 AM', $this->last(Audit::SCHEDULE_REMOVED)->description);

        // Join requests.
        $ana = $this->enroll($class, User::factory()->create(['name' => 'Ana Cruz', 'id_number' => 'S-1']), Enrollment::PENDING);
        $this->actingAs($teacher)->post('/enrollments/'.Enrollment::where('student_id', $ana->id)->value('id').'/approve');
        $this->assertSame('Ana Cruz (S-1) approved to join CAPSTONE 2 · A & B', $this->last(Audit::JOIN_APPROVED)->description);

        // Session: created, stages, edited, archived, restored.
        $this->actingAs($teacher)->post("/classes/{$class->id}/sessions", ['date' => '2026-11-12', 'title' => 'Week 3']);
        $session = $class->attendanceSessions()->sole();
        $this->assertSame('Created session CAPSTONE 2 · A & B · Nov 12, 2026 (Week 3) · grace 15 min', $this->last(Audit::SESSION_CREATED)->description);
        $this->actingAs($teacher)->post("/sessions/{$session->id}/stages/time_in/open");
        $this->assertSame('Opened Time In · CAPSTONE 2 · A & B · Nov 12, 2026 (Week 3)', $this->last(Audit::STAGE_OPENED)->description);
        $this->actingAs($teacher)->post("/sessions/{$session->id}/stages/time_in/close");
        $this->assertSame('Closed Time In · CAPSTONE 2 · A & B · Nov 12, 2026 (Week 3)', $this->last(Audit::STAGE_CLOSED)->description);
        $this->actingAs($teacher)->put("/sessions/{$session->id}", ['title' => 'Week 3 lab', 'grace_minutes' => 10]);
        $this->assertSame('Session CAPSTONE 2 · A & B · Nov 12, 2026 (Week 3 lab): Title: Week 3 → Week 3 lab; Grace (min): 15 → 10', $this->last(Audit::SESSION_UPDATED)->description);
        $this->actingAs($teacher)->post("/sessions/{$session->id}/archive");
        $this->assertStringStartsWith('Archived session CAPSTONE 2', $this->last(Audit::SESSION_ARCHIVED)->description);
        $this->actingAs($teacher)->post("/sessions/{$session->id}/restore");
        $this->assertStringStartsWith('Restored session CAPSTONE 2', $this->last(Audit::SESSION_RESTORED)->description);

        // Announcement.
        $this->actingAs($teacher)->post("/classes/{$class->id}/announcements", ['type' => 'general', 'title' => 'Bring laptops', 'body' => 'For the lab.']);
        $this->assertSame('CAPSTONE 2 · A & B: "Bring laptops"', $this->last(Audit::ANNOUNCEMENT_POSTED)->description);

        // A student removed.
        $this->actingAs($teacher)->post('/enrollments/'.Enrollment::where('student_id', $ana->id)->value('id').'/remove');
        $this->assertSame('Ana Cruz (S-1) removed from CAPSTONE 2 · A & B', $this->last(Audit::STUDENT_REMOVED)->description);
        $this->assertSame($teacher->id, $this->last(Audit::STUDENT_REMOVED)->causer_id);
    }

    public function test_student_actions_are_logged(): void
    {
        $this->freezeManila('2026-11-12 08:00:00');
        $class = $this->makeClass(null, ['subject' => 'ITE101', 'section' => 'B', 'code' => 'ABCD234']);
        $student = User::factory()->create(['name' => 'Ana Cruz', 'id_number' => 'S-1', 'password' => 'Secret@123']);

        // Logged in (with the ID number).
        $this->post('/login', ['login' => 'S-1', 'password' => 'Secret@123']);
        $this->assertStringStartsWith('Ana Cruz (S-1) logged in with their ID number · IP ', $this->last(Audit::LOGGED_IN)->description);
        $this->assertSame(User::STUDENT, $this->last(Audit::LOGGED_IN)->getProperty('causer_role'));

        // Join requested.
        $this->actingAs($student)->post('/classes/join', ['code' => 'ABCD234']);
        $this->assertSame('Ana Cruz (S-1) asked to join ITE101 · B (code ABCD234)', $this->last(Audit::JOIN_REQUESTED)->description);
        Enrollment::where('student_id', $student->id)->update(['status' => Enrollment::APPROVED]);

        // Scanned (a repeat scan isn't logged again).
        $session = AttendanceSession::factory()->for($class, 'schoolClass')->create(['date' => '2026-11-12', 'grace_minutes' => 15]);
        $session->update(['time_in_opened_at' => now()]);
        $token = app(QrTokenService::class)->issue($session, 'time_in');
        $this->actingAs($student)->postJson('/scan', ['t' => $token])->assertJson(['ok' => true]);
        $this->actingAs($student)->postJson('/scan', ['t' => $token]);
        $this->assertSame(1, Activity::where('event', Audit::ATTENDANCE_SCANNED)->count());
        $this->assertSame('Ana Cruz (S-1) · ITE101 · B · Nov 12, 2026: Time In at 8:00 AM (Present)', $this->last(Audit::ATTENDANCE_SCANNED)->description);

        // Correction requested.
        $record = AttendanceRecord::where('student_id', $student->id)->sole();
        $record->update(['status' => 'absent']);
        $this->actingAs($student)->post("/records/{$record->id}/corrections", ['requested_status' => 'present', 'reason' => 'I scanned but my phone lost signal.']);
        $this->assertSame('Ana Cruz (S-1) · ITE101 · B · Nov 12, 2026: asked Absent → Present — "I scanned but my phone lost signal."', $this->last(Audit::CORRECTION_REQUESTED)->description);

        // Teacher rejects it, with a note.
        $correction = $record->corrections()->sole();
        $this->flushSession(); // the teacher uses another browser
        $this->actingAs($class->teacher)->post("/corrections/{$correction->id}/reject", ['teacher_note' => 'No scan found.']);
        $this->assertSame('Ana Cruz (S-1) · ITE101 · B · Nov 12, 2026: Absent → Present rejected — note: "No scan found."', $this->last(Audit::CORRECTION_REJECTED)->description);

        // Profile and password.
        $this->flushSession();
        $this->actingAs($student)->put('/profile', ['name' => 'Ana M. Cruz', 'id_number' => 'S-1', 'email' => $student->email, 'course' => 'BSIT', 'year_level' => 4, 'section' => 'B']);
        $this->assertSame('Ana M. Cruz (S-1) updated their profile: Name: Ana Cruz → Ana M. Cruz; Course: — → BSIT; Year level: — → 4; Section: — → B', $this->last(Audit::PROFILE_UPDATED)->description);
        $this->actingAs($student)->put('/password', ['current_password' => 'Secret@123', 'password' => 'Newer@1234', 'password_confirmation' => 'Newer@1234']);
        $this->assertSame('Ana M. Cruz (S-1) changed their password', $this->last(Audit::PASSWORD_CHANGED)->description);
    }

    public function test_log_page_filters_by_role_search_and_grouped_action(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
        $teacher = User::factory()->teacher()->create(['name' => 'Bryan Timbreza']);
        $student = User::factory()->create(['name' => 'Ana Cruz', 'id_number' => 'S-77']);

        Audit::log(Audit::USER_ADDED, 'Added Student Someone', causer: $admin);
        Audit::log(Audit::CLASS_CREATED, 'Created class ITE101 · B', causer: $teacher);
        Audit::log(Audit::ATTENDANCE_SCANNED, 'Ana Cruz (S-77) · ITE101 · B: Time In', causer: $student);

        $details = fn (string $q) => $this->actingAs($admin)->get('/admin/activity?'.$q)->assertOk()->viewData('entries')->pluck('description')->all();

        $this->assertCount(3, $details(''));
        $this->assertSame(['Created class ITE101 · B'], $details('role=teacher'));
        $this->assertSame(['Ana Cruz (S-77) · ITE101 · B: Time In'], $details('role=student'));
        $this->assertSame(['Ana Cruz (S-77) · ITE101 · B: Time In'], $details('q=s-77'));      // ID number
        $this->assertSame(['Created class ITE101 · B'], $details('q=timbreza'));               // name
        $this->assertSame(['Created class ITE101 · B'], $details('action=class_created'));
        $this->actingAs($admin)->get('/admin/activity?role=boss')->assertSessionHasErrors('role');

        $this->actingAs($admin)->get('/admin/activity')
            ->assertSee('<optgroup label="Attendance">', false)->assertSee('<optgroup label="Classes &amp; students">', false)
            ->assertSee('Attendance scanned')->assertSee('Teacher')->assertSee('Student');
    }
}
