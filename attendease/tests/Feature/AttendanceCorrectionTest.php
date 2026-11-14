<?php

namespace Tests\Feature;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\SchoolClass;
use App\Models\User;
use App\Notifications\CorrectionDecided;
use App\Notifications\CorrectionRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** FR-ATT-18: students appeal Absent / Late records; teachers approve or reject. */
class AttendanceCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;
    private User $student;
    private AttendanceSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->class = $this->makeClass(null, ['semester_start' => '2026-08-01', 'semester_end' => '2026-12-15']);
        $this->student = $this->enroll($this->class);
        $this->session = AttendanceSession::factory()->for($this->class, 'schoolClass')->completed()->create(['date' => now()->toDateString(), 'title' => 'Week 5']);
    }

    private function record(string $status, ?User $student = null): AttendanceRecord
    {
        return AttendanceRecord::factory()->create([
            'attendance_session_id' => $this->session->id,
            'student_id' => ($student ?? $this->student)->id,
            'status' => $status,
        ]);
    }

    private function ask(AttendanceRecord $r, array $data = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->student)->post("/records/{$r->id}/corrections", array_merge([
            'requested_status' => 'present',
            'reason' => 'I was in class but my phone could not scan the QR code.',
        ], $data));
    }

    public function test_student_sees_request_correction_only_on_absent_or_late_records(): void
    {
        $absent = $this->record('absent');
        $page = $this->actingAs($this->student)->get('/my/attendance')->assertOk();
        $page->assertSee('Request correction')->assertSee(route('corrections.store', $absent), false);

        $absent->update(['status' => 'present']);
        $this->actingAs($this->student)->get('/my/attendance')->assertDontSee('Request correction');
    }

    public function test_student_requests_a_correction_and_the_teacher_is_notified(): void
    {
        $r = $this->record('absent');

        $this->ask($r)->assertSessionHasNoErrors()->assertSessionHas('status', 'Correction request sent. Your teacher will review it.');

        $c = AttendanceCorrection::sole();
        $this->assertSame(['absent', 'present', 'pending', $this->student->id], [$c->current_status, $c->requested_status, $c->status, $c->student_id]);
        Notification::assertSentTo($this->class->teacher, CorrectionRequested::class);

        // The history shows it as Pending, and no second request can be made meanwhile.
        $this->actingAs($this->student)->get('/my/attendance')->assertSee('Pending')->assertSee('asked for Present')->assertDontSee('Request correction');
        $this->ask($r)->assertSessionHasErrorsIn('correction'.$r->id, 'reason');
        $this->assertSame(1, AttendanceCorrection::count());
    }

    public function test_requests_are_validated(): void
    {
        $late = $this->record('late');

        $this->ask($late, ['requested_status' => 'late'])->assertSessionHasErrorsIn('correction'.$late->id, 'requested_status'); // Late → Late
        $this->ask($late, ['requested_status' => 'absent'])->assertSessionHasErrorsIn('correction'.$late->id, 'requested_status');
        $this->ask($late, ['reason' => 'short'])->assertSessionHasErrorsIn('correction'.$late->id, 'reason');
        $this->ask($late, ['reason' => ''])->assertSessionHasErrorsIn('correction'.$late->id, 'reason');
        $this->assertSame(0, AttendanceCorrection::count());

        $present = $this->record('present', $this->enroll($this->class));
        $this->ask($present, [], $present->student)->assertSessionHasErrorsIn('correction'.$present->id, 'requested_status');

        // Absent may ask for Present or Late.
        $absent = $this->record('absent', $other = $this->enroll($this->class));
        $this->ask($absent, ['requested_status' => 'late'], $other)->assertSessionHasNoErrors();
    }

    public function test_only_the_records_own_student_in_the_class_can_ask(): void
    {
        $r = $this->record('absent');

        $this->ask($r, [], $this->enroll($this->class))->assertForbidden();   // a classmate
        $this->ask($r, [], $this->class->teacher)->assertForbidden();
        $this->ask($r, [], User::factory()->create())->assertForbidden();

        \App\Models\Enrollment::where('student_id', $this->student->id)->update(['status' => 'removed']);
        $this->ask($r)->assertForbidden();

        \App\Models\Enrollment::where('student_id', $this->student->id)->update(['status' => 'approved']);
        $this->session->delete(); // archived
        $this->ask($r)->assertForbidden();
        $this->assertSame(0, AttendanceCorrection::count());
    }

    public function test_teacher_sees_requests_and_approving_changes_the_record(): void
    {
        $r = $this->record('absent');
        $this->ask($r);
        $c = AttendanceCorrection::sole();
        $t = $this->class->teacher;

        $this->actingAs($t)->get("/classes/{$this->class->id}")->assertSee('Corrections')->assertSee('title="1 pending"', false);
        $this->actingAs($t)->get('/dashboard')->assertSee('1 correction');
        $this->actingAs($t)->get("/classes/{$this->class->id}/corrections")->assertOk()
            ->assertSee($this->student->name)->assertSee('my phone could not scan')->assertSee('Approve → Present');

        $this->actingAs($t)->post("/corrections/{$c->id}/approve", ['teacher_note' => 'Confirmed with the seat plan.'])->assertSessionHasNoErrors();

        $c->refresh();
        $r->refresh();
        $this->assertSame(['approved', $t->id, 'Confirmed with the seat plan.'], [$c->status, $c->decided_by, $c->teacher_note]);
        $this->assertSame(['present', 'manual'], [$r->status, $r->source]);
        Notification::assertSentTo($this->student, CorrectionDecided::class, fn ($n) => $n->correction->status === 'approved');

        // It counts in the report, and the student sees Approved with the note.
        $row = collect($this->actingAs($t)->get("/classes/{$this->class->id}/report")->viewData('rows'))->first();
        $this->assertSame(1, $row['present']);
        $this->actingAs($this->student)->get('/my/attendance')->assertSee('Approved')->assertSee('Confirmed with the seat plan.');

        // Already handled: a second decision is refused.
        $this->actingAs($t)->post("/corrections/{$c->id}/reject")->assertSessionHasErrors('enrollment');
        $this->assertSame('approved', $c->fresh()->status);
    }

    public function test_rejecting_keeps_the_record_and_the_student_may_ask_again(): void
    {
        $r = $this->record('late');
        $this->ask($r);
        $c = AttendanceCorrection::sole();

        $this->actingAs($this->class->teacher)->post("/corrections/{$c->id}/reject", ['teacher_note' => 'You arrived at 8:40.']);

        $this->assertSame('rejected', $c->fresh()->status);
        $this->assertSame('late', $r->fresh()->status);
        Notification::assertSentTo($this->student, CorrectionDecided::class, fn ($n) => $n->correction->status === 'rejected');

        $this->actingAs($this->student)->get('/my/attendance')->assertSee('Rejected')->assertSee('You arrived at 8:40.')->assertSee('Request again');
        $this->ask($r)->assertSessionHasNoErrors();
        $this->assertSame(2, AttendanceCorrection::count());
    }

    public function test_only_the_classs_teacher_can_see_or_decide(): void
    {
        $r = $this->record('absent');
        $this->ask($r);
        $c = AttendanceCorrection::sole();

        foreach ([$this->student, User::factory()->create()] as $user) {
            $this->actingAs($user)->get("/classes/{$this->class->id}/corrections")->assertForbidden();
            $this->actingAs($user)->post("/corrections/{$c->id}/approve")->assertForbidden();
            $this->actingAs($user)->post("/corrections/{$c->id}/reject")->assertForbidden();
        }
        $this->assertSame('pending', $c->fresh()->status);
        $this->assertSame('absent', $r->fresh()->status);
    }

    public function test_filter_tabs_on_the_corrections_page(): void
    {
        $a = $this->record('absent');
        $b = $this->record('late', $other = $this->enroll($this->class));
        $this->ask($a);
        $this->ask($b, [], $other);
        $this->actingAs($this->class->teacher)->post('/corrections/'.AttendanceCorrection::where('student_id', $other->id)->value('id').'/reject');

        $list = fn ($s) => $this->actingAs($this->class->teacher)->get("/classes/{$this->class->id}/corrections?status={$s}")
            ->viewData('corrections')->pluck('status')->all();

        $this->assertSame(['pending'], $list('pending'));
        $this->assertSame(['rejected'], $list('rejected'));
        $this->assertSame([], $list('approved'));
        $this->assertSame(['pending', 'rejected'], $list('all'));
    }
}
