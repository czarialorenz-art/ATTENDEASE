<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportController;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\ClassSchedule;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\User;
use App\Notifications\JoinRequestDecided;
use App\Services\ClassStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * FR-CLS-13 (approve all from 1 request), FR-RPT-05 (report filters),
 * FR-CLS-15 ("…" menu and View attendance on the Students tab), FR-ATT-20 (new session is today).
 */
class TeacherClassToolsTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;
    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeManila('2026-11-24 09:00:00'); // a Tuesday
        $this->teacher = User::factory()->teacher()->create(['name' => 'Prof. Ana Reyes']);
        $this->class = $this->makeClass($this->teacher, [
            'subject' => 'ITE101', 'section' => 'BSIT-1A',
            'semester_start' => '2026-08-01', 'semester_end' => '2026-12-15',
        ]);
    }

    private function enrollment(User $student): Enrollment
    {
        return Enrollment::where('class_id', $this->class->id)->where('student_id', $student->id)->firstOrFail();
    }

    private function held(string $date, array $attrs = []): AttendanceSession
    {
        return AttendanceSession::factory()->for($this->class, 'schoolClass')->create(['date' => $date, 'time_in_opened_at' => now(), ...$attrs]);
    }

    private function mark(AttendanceSession $s, User $student, string $status): AttendanceRecord
    {
        return AttendanceRecord::factory()->create(['attendance_session_id' => $s->id, 'student_id' => $student->id, 'status' => $status]);
    }

    /* ---------- FR-CLS-13: Approve all ---------- */

    public function test_fr_cls_13_approve_all_approves_every_pending_request_and_notifies_each(): void
    {
        Notification::fake();
        $pending = collect(range(1, 3))->map(fn () => $this->enroll($this->class, null, Enrollment::PENDING));
        $approved = $this->enroll($this->class);
        $removed = $this->enroll($this->class, null, Enrollment::REMOVED);
        $removedAt = $this->enrollment($removed)->decided_at;

        $page = $this->actingAs($this->teacher)->get("/classes/{$this->class->id}/students")->assertOk()
            ->assertSee('Approve all (3)');
        $ids = $page->viewData('pending')->pluck('id')->all();

        $this->actingAs($this->teacher)->post("/classes/{$this->class->id}/enrollments/approve-all", ['ids' => [
            ...$ids, $this->enrollment($approved)->id, $this->enrollment($removed)->id,
        ]])->assertSessionHas('status', '3 students approved.');

        foreach ($pending as $student) {
            $this->assertSame(Enrollment::APPROVED, $this->enrollment($student)->status);
        }
        Notification::assertSentTo($pending->all(), JoinRequestDecided::class);
        Notification::assertNotSentTo([$approved, $removed], JoinRequestDecided::class);
        $this->assertSame(Enrollment::REMOVED, $this->enrollment($removed)->status);
        $this->assertEquals($removedAt, $this->enrollment($removed)->decided_at);

        // With one pending request the button still shows.
        $one = $this->enroll($this->class, null, Enrollment::PENDING);
        $this->actingAs($this->teacher)->get("/classes/{$this->class->id}/students")->assertSee('Approve all (1)');
        $this->actingAs($this->teacher)->post("/classes/{$this->class->id}/enrollments/approve-all", ['ids' => [$this->enrollment($one)->id]])
            ->assertSessionHas('status', '1 student approved.');
    }

    public function test_fr_cls_13_another_teacher_cannot_approve_all(): void
    {
        $student = $this->enroll($this->class, null, Enrollment::PENDING);

        $this->actingAs($this->makeClass()->teacher)
            ->post("/classes/{$this->class->id}/enrollments/approve-all", ['ids' => [$this->enrollment($student)->id]])
            ->assertForbidden();
        $this->assertSame(Enrollment::PENDING, $this->enrollment($student)->status);
    }

    /* ---------- FR-RPT-05: report status filter and search ---------- */

    /** Ana: P, A, A · Ben: L, L, P · Cara: P, P, P (three held sessions in November). */
    private function reportScenario(): array
    {
        $ana = $this->enroll($this->class, User::factory()->create(['name' => 'Ana Dela Cruz', 'id_number' => '2023-00001']));
        $ben = $this->enroll($this->class, User::factory()->create(['name' => 'Ben Lim', 'id_number' => '2023-00002']));
        $cara = $this->enroll($this->class, User::factory()->create(['name' => 'Cara Uy', 'id_number' => '2023-00003']));

        [$s1, $s2, $s3] = [$this->held('2026-11-02'), $this->held('2026-11-09'), $this->held('2026-11-16')];
        $this->mark($s1, $ana, 'present'); $this->mark($s2, $ana, 'absent'); // s3: no record = absent
        $this->mark($s1, $ben, 'late'); $this->mark($s2, $ben, 'late'); $this->mark($s3, $ben, 'present');
        foreach ([$s1, $s2, $s3] as $s) {
            $this->mark($s, $cara, 'present');
        }

        return compact('ana', 'ben', 'cara');
    }

    private function names($response): array
    {
        return collect($response->viewData('rows'))->pluck('student.name')->all();
    }

    public function test_fr_rpt_05_status_filters_keep_students_with_that_status_most_first(): void
    {
        $this->reportScenario();
        $url = "/classes/{$this->class->id}/report?period=month&date=2026-11-10";

        $all = $this->actingAs($this->teacher)->get($url)->assertOk()->assertSee('Showing 3 of 3 students');
        $this->assertSame(['Ana Dela Cruz', 'Ben Lim', 'Cara Uy'], $this->names($all)); // All = by surname, as before
        $all->assertDontSee('data-filter-label', false);

        $this->assertSame(['Cara Uy', 'Ana Dela Cruz', 'Ben Lim'], $this->names($this->actingAs($this->teacher)->get($url.'&status=present')));
        $this->assertSame(['Ben Lim'], $this->names($this->actingAs($this->teacher)->get($url.'&status=late')));

        $absent = $this->actingAs($this->teacher)->get($url.'&status=absent')
            ->assertSee('Showing 1 of 3 students')->assertSee('Status: Absent')
            ->assertSee('Clear')->assertSee('name="period" value="month" class="peer sr-only" checked', false);
        $this->assertSame(['Ana Dela Cruz'], $this->names($absent));
        $this->assertSame(2, $absent->viewData('rows')[0]['absent']); // the held session with no record counts

        // Nothing matches: a friendly message.
        $this->actingAs($this->teacher)->get("/classes/{$this->class->id}/report?period=day&date=2026-11-16&status=late")
            ->assertSee('Showing 0 of 3 students')->assertSee('No students match these filters for this period.');
    }

    public function test_fr_rpt_05_search_by_name_or_id_number(): void
    {
        $this->reportScenario();
        $url = "/classes/{$this->class->id}/report?period=semester";

        $this->assertSame(['Ana Dela Cruz'], $this->names($this->actingAs($this->teacher)->get($url.'&q=dela+cruz')->assertSee('Search: dela cruz')));
        $this->assertSame(['Ben Lim'], $this->names($this->actingAs($this->teacher)->get($url.'&q=2023-00002')));
        $this->assertSame(['Cara Uy'], $this->names($this->actingAs($this->teacher)->get($url.'&q=uy&status=present')
            ->assertSee('Status: Present · Search: uy')));
    }

    public function test_fr_rpt_05_filters_are_carried_into_the_pdf(): void
    {
        $this->reportScenario();

        // A search matching several students keeps the class grid (one match would show the student record, FR-RPT-06).
        $page = $this->actingAs($this->teacher)->get("/classes/{$this->class->id}/report?period=month&month_mode=whole&month=2026-11&status=absent&q=2023-0000");
        $pdfUrl = route('reports.pdf', [$this->class, ...$page->viewData('query')]);
        $this->assertSame(['period' => 'month', 'month_mode' => 'whole', 'month' => '2026-11', 'status' => 'absent', 'q' => '2023-0000'], $page->viewData('query'));
        $page->assertSee(e($pdfUrl), false);

        // The PDF is built from the same filtered rows and names the filter in its heading.
        $pdf = $this->actingAs($this->teacher)->get($pdfUrl)->assertOk();
        $pdf->assertHeader('content-type', 'application/pdf');
        // (The PDF bytes are compressed, so render its view from the same request data.)
        $data = (new \ReflectionMethod(ReportController::class, 'data'))
            ->invoke(app(ReportController::class), Request::create($pdfUrl), $this->class);
        $html = view('pdf.attendance-report', $data)->render();
        $this->assertStringContainsString('Status: Absent · Search: 2023-0000 · 1 of 3 students with at least one Absent', $html);
        $this->assertStringContainsString('Dela Cruz, Ana', $html);
        $this->assertStringNotContainsString('Lim, Ben', $html);
    }

    public function test_fr_rpt_05_invalid_filters_are_rejected(): void
    {
        $base = "/classes/{$this->class->id}/report";

        $this->actingAs($this->teacher)->get($base.'?status=excused')->assertSessionHasErrors('status');
        $this->actingAs($this->teacher)->get($base.'.pdf?status=excused')->assertSessionHasErrors('status');
        $this->actingAs($this->teacher)->get($base.'?q='.str_repeat('a', 101))->assertSessionHasErrors('q');
        $this->actingAs($this->teacher)->get($base.'?status=all&q=')->assertOk();
    }

    /* ---------- FR-CLS-15: "…" menu and View attendance ---------- */

    public function test_fr_cls_15_view_attendance_matches_the_class_stats_and_lists_records_newest_first(): void
    {
        ['ana' => $ana, 'ben' => $ben] = $this->reportScenario();
        $this->held('2026-11-23', ['title' => 'Week 13'])->records()->create(['student_id' => $ana->id, 'status' => 'late', 'time_in_at' => now()]);

        $students = $this->actingAs($this->teacher)->get("/classes/{$this->class->id}/students")->assertOk()
            ->assertSee('View attendance')->assertSee('Remove from class')
            ->assertSee(route('classes.student-attendance', [$this->class, $ana]), false)
            ->assertDontSee('>Remove</button>', false);
        $expected = app(ClassStatsService::class)->termReport($this->class, collect([$ana]))['rows']->first();
        $counts = fn ($row) => array_diff_key($row, ['student' => 1]); // present, late, absent, rate, marks
        $this->assertSame(50.0, $expected['rate']); // P, A, A, L
        // Rate badges: Ana 50% (red); Ben and Cara 75% (amber), as they have no record for Nov 23.
        $students->assertSee('<span class="badge-absent" title="Attendance rate this semester" data-rate>50%</span>', false)
            ->assertSee('<span class="badge-late" title="Attendance rate this semester" data-rate>75%</span>', false);

        $page = $this->actingAs($this->teacher)->get(route('classes.student-attendance', [$this->class, $ana]))->assertOk();
        $this->assertSame($counts($expected), $counts($page->viewData('mine')));
        $page->assertSeeInOrder(['data-stat="present">1', 'data-stat="late">1', 'data-stat="absent">2', 'data-stat="rate">50%'], false);

        // Records in this class only, newest first, each linking to its session.
        $records = $page->viewData('records');
        $this->assertSame(['2026-11-23', '2026-11-09', '2026-11-02'], $records->map(fn ($r) => $r->attendanceSession->date->toDateString())->all());
        $page->assertSeeInOrder(['Mon, Nov 23, 2026', 'Week 13', 'Late', 'Mon, Nov 9, 2026', 'Absent', 'Mon, Nov 2, 2026', 'Present'])
            ->assertSee(route('sessions.show', $records[0]->attendance_session_id).'#student-'.$ana->id, false);
        $this->actingAs($this->teacher)->get(route('sessions.show', $records[0]->attendance_session_id))->assertSee('id="student-'.$ana->id.'"', false);

        // Same numbers as the student's own class page.
        $this->assertSame($counts($expected), $counts($this->actingAs($ana)->get("/classes/{$this->class->id}")->viewData('mine')));
        $this->assertNotSame($counts($expected), $counts($this->actingAs($this->teacher)->get(route('classes.student-attendance', [$this->class, $ben]))->viewData('mine')));
    }

    public function test_fr_cls_15_remove_from_the_menu_still_keeps_past_records(): void
    {
        Notification::fake();
        $student = $this->enroll($this->class);
        $this->mark($this->held('2026-11-02'), $student, 'present');

        $this->actingAs($this->teacher)->get("/classes/{$this->class->id}/students")
            ->assertSee('data-confirm="Remove '.$student->name.' from this class? Their past records are kept."', false);
        $this->actingAs($this->teacher)->post('/enrollments/'.$this->enrollment($student)->id.'/remove')->assertSessionHasNoErrors();

        $this->assertSame(Enrollment::REMOVED, $this->enrollment($student)->status);
        $this->assertSame(1, AttendanceRecord::where('student_id', $student->id)->count());
        // The teacher can still look back at a removed student's attendance.
        $this->actingAs($this->teacher)->get(route('classes.student-attendance', [$this->class, $student]))->assertOk()->assertSee('removed');
    }

    public function test_fr_cls_15_only_the_class_teacher_can_view_a_students_attendance(): void
    {
        $student = $this->enroll($this->class);
        $url = route('classes.student-attendance', [$this->class, $student]);

        $this->actingAs($student)->get($url)->assertForbidden();
        $this->actingAs($this->enroll($this->class))->get($url)->assertForbidden();
        $this->actingAs($this->makeClass()->teacher)->get($url)->assertForbidden();
        $this->actingAs($this->teacher)->get($url)->assertOk();

        // Someone who never was in the class: not found.
        $this->actingAs($this->teacher)->get(route('classes.student-attendance', [$this->class, User::factory()->create()]))->assertNotFound();
        $this->actingAs($this->teacher)->get(route('classes.student-attendance', [$this->class, $this->enroll($this->class, null, Enrollment::PENDING)]))->assertNotFound();
    }

    /* ---------- FR-ATT-20: a new session is today, with today's schedule ---------- */

    public function test_fr_att_20_session_date_is_today_even_if_another_date_is_posted(): void
    {
        $this->freezeManila('2026-11-24 23:30:00'); // late evening in Manila (15:30 UTC)
        $this->actingAs($this->teacher)->post("/classes/{$this->class->id}/sessions", ['date' => '2026-09-01', 'title' => 'Week 13', 'grace_minutes' => 10])
            ->assertRedirect();

        $s = AttendanceSession::sole();
        $this->assertSame(['2026-11-24', 'Week 13', 10], [$s->date->toDateString(), $s->title, $s->grace_minutes]);
    }

    public function test_fr_att_20_form_shows_todays_schedule_without_a_date_field(): void
    {
        ClassSchedule::factory()->create(['class_id' => $this->class->id, 'day_of_week' => 2, 'start_time' => '13:00:00', 'end_time' => '15:00:00', 'room' => 'Room 204']);
        ClassSchedule::factory()->create(['class_id' => $this->class->id, 'day_of_week' => 2, 'start_time' => '08:00:00', 'end_time' => '11:00:00', 'room' => 'Tesla Lab, 3rd Floor']);
        ClassSchedule::factory()->create(['class_id' => $this->class->id, 'day_of_week' => 4, 'start_time' => '08:00:00', 'end_time' => '09:00:00', 'room' => 'Thursday Room']);

        $this->actingAs($this->teacher)->get("/classes/{$this->class->id}/sessions")->assertOk()
            ->assertSeeInOrder(['Today · Tuesday, Nov 24', '8:00 AM – 11:00 AM', 'Tesla Lab, 3rd Floor', 'Today · Tuesday, Nov 24', '1:00 PM – 3:00 PM', 'Room 204'])
            ->assertDontSee('Thursday Room')
            ->assertDontSee('data-no-schedule', false)
            ->assertDontSee('name="date"', false)
            ->assertDontSee('data-semester-start', false) // the old date-warning script is gone
            ->assertSee('name="title"', false)->assertSee('name="grace_minutes"', false);
    }

    public function test_fr_att_20_no_schedule_today_shows_the_make_up_notice_and_still_allows_a_session(): void
    {
        ClassSchedule::factory()->create(['class_id' => $this->class->id, 'day_of_week' => 1, 'start_time' => '08:00:00', 'end_time' => '09:00:00']);

        $this->actingAs($this->teacher)->get("/classes/{$this->class->id}/sessions")
            ->assertSee("This class isn't scheduled today. You can still create a session for a make-up class.", false);
        $this->actingAs($this->teacher)->post("/classes/{$this->class->id}/sessions")->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, AttendanceSession::count());
    }

    public function test_fr_att_20_existing_session_today_shows_a_link_but_allows_another(): void
    {
        $url = "/classes/{$this->class->id}/sessions";
        $this->actingAs($this->teacher)->get($url)->assertDontSee('A session for today already exists.');

        $yesterday = AttendanceSession::factory()->for($this->class, 'schoolClass')->create(['date' => '2026-11-23']);
        $this->actingAs($this->teacher)->get($url)->assertDontSee('A session for today already exists.');

        $this->actingAs($this->teacher)->post($url);
        $today = AttendanceSession::whereKeyNot($yesterday->id)->sole();
        $this->actingAs($this->teacher)->get($url)
            ->assertSee('A session for today already exists.')
            ->assertSee(route('sessions.show', $today), false);

        $this->actingAs($this->teacher)->post($url)->assertSessionHasNoErrors();
        $this->assertSame(2, AttendanceSession::whereDate('date', '2026-11-24')->count());
    }

    public function test_fr_att_20_outside_the_semester_is_blocked(): void
    {
        $this->class->update(['semester_end' => '2026-11-20']);
        $url = "/classes/{$this->class->id}/sessions";

        $this->actingAs($this->teacher)->get($url)
            ->assertSee('This date is after the semester ends (Nov 20, 2026)')
            ->assertSee('Fix semester dates')
            ->assertDontSee('data-no-schedule', false);
        $this->actingAs($this->teacher)->post($url, ['date' => '2026-11-10'])->assertSessionHasErrors('date');
        $this->assertSame(0, AttendanceSession::count());
    }
}
