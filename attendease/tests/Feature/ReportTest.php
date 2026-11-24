<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Enrollment;
use App\Models\User;
use App\Support\FileName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Attendance report (FR-RPT) and the student's own attendance summary. */
class ReportTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(): array
    {
        $teacher = User::factory()->create(['name' => 'Prof. Ana Reyes']);
        $class = $this->makeClass($teacher, [
            'subject' => 'ITE101', 'section' => 'BSIT-1A', 'school_term' => '2026-2027', 'semester' => '1st',
            'semester_start' => '2026-08-01', 'semester_end' => '2026-12-15',
        ]);
        $alice = $this->enroll($class, User::factory()->create(['name' => 'Alice Aquino', 'id_number' => '2023-00001']));
        $bob = $this->enroll($class, User::factory()->create(['name' => 'Bob Bautista', 'id_number' => '2023-00002']));

        $held = fn ($date) => AttendanceSession::factory()->for($class, 'schoolClass')->create(['date' => $date, 'time_in_opened_at' => now()]);
        $s1 = $held('2026-09-01');
        $s2 = $held('2026-09-08');
        $outside = $held('2027-01-10'); // outside the semester range
        AttendanceSession::factory()->for($class, 'schoolClass')->create(['date' => '2026-09-15']); // never opened: not held

        AttendanceRecord::factory()->create(['attendance_session_id' => $s1->id, 'student_id' => $alice->id, 'status' => 'present']);
        AttendanceRecord::factory()->create(['attendance_session_id' => $s2->id, 'student_id' => $alice->id, 'status' => 'late']);
        AttendanceRecord::factory()->create(['attendance_session_id' => $outside->id, 'student_id' => $alice->id, 'status' => 'present']);
        AttendanceRecord::factory()->create(['attendance_session_id' => $s1->id, 'student_id' => $bob->id, 'status' => 'absent']);

        return compact('teacher', 'class', 'alice', 'bob');
    }

    public function test_fr_rpt_01_02_report_counts_semester_sessions_and_has_header(): void
    {
        ['teacher' => $t, 'class' => $c, 'bob' => $bob] = $this->scenario();
        Enrollment::where('student_id', $bob->id)->update(['status' => 'removed']);

        $res = $this->actingAs($t)->get("/classes/{$c->id}/report")->assertOk();

        $this->assertSame(2, $res->viewData('held')); // the 2027 session is outside the range
        $alice = collect($res->viewData('rows'))->firstWhere('student.name', 'Alice Aquino');
        $this->assertSame([1, 1, 0, 100.0], [$alice['present'], $alice['late'], $alice['absent'], $alice['rate']]);

        $res->assertSee(config('app.school_name'))->assertSee('ITE101')->assertSee('BSIT-1A')->assertSee('2026-2027')
            ->assertSee('1st')->assertSee('Prof. Ana Reyes')->assertSee(now()->format('M j, Y'))
            ->assertSee('Bautista, Bob')->assertSee('removed'); // removed students' history is kept
    }

    public function test_fr_rpt_01_report_period_can_be_day_week_month_or_semester(): void
    {
        $teacher = User::factory()->create();
        $class = $this->makeClass($teacher, ['semester_start' => '2026-08-01', 'semester_end' => '2026-12-15']);
        $student = $this->enroll($class);

        // Wed Sep 2, Fri Sep 4 (same Mon–Sun week), Mon Sep 7 (next week), Oct 5 (next month).
        foreach (['2026-09-02' => 'present', '2026-09-04' => 'late', '2026-09-07' => 'present', '2026-10-05' => 'present'] as $date => $status) {
            $s = AttendanceSession::factory()->for($class, 'schoolClass')->create(['date' => $date, 'time_in_opened_at' => now()]);
            AttendanceRecord::factory()->create(['attendance_session_id' => $s->id, 'student_id' => $student->id, 'status' => $status]);
        }

        $report = fn (string $query) => $this->actingAs($teacher)->get("/classes/{$class->id}/report?{$query}")->assertOk();
        $counts = function ($res) {
            $r = collect($res->viewData('rows'))->first();

            return [$res->viewData('held'), $r['present'], $r['late'], $r['absent']];
        };

        $this->assertSame([1, 0, 1, 0], $counts($report('period=day&date=2026-09-04')));
        $this->assertSame([2, 1, 1, 0], $counts($report('period=week&date=2026-09-03')));
        $this->assertSame([3, 2, 1, 0], $counts($report('period=month&date=2026-09-20')));
        $this->assertSame([4, 3, 1, 0], $counts($report('period=semester')));
        $this->assertSame([4, 3, 1, 0], $counts($report(''))); // semester is the default
        $this->assertSame([0, 0, 0, 0], $counts($report('period=day&date=2026-09-03')));

        $report('period=week&date=2026-09-03')->assertSee('Weekly Attendance Summary')->assertSee('Aug 31 – Sep 6, 2026');
        $report('period=month&date=2026-09-20')->assertSee('Monthly Attendance Summary')->assertSee('September 2026');
        $report('period=day&date=2026-09-04')->assertSee('Daily Attendance Summary')->assertSee('Friday, Sep 4, 2026');

        $this->actingAs($teacher)->get("/classes/{$class->id}/report?period=year")->assertSessionHasErrors('period');
        $this->actingAs($teacher)->get("/classes/{$class->id}/report?period=day&date=nope")->assertSessionHasErrors('date');

        // Downloads use the same period and say so in the file name.
        $base = FileName::safe($class->subject).'_'.FileName::safe($class->section);
        $this->actingAs($teacher)->get("/classes/{$class->id}/report.pdf?period=month&date=2026-09-20")
            ->assertOk()->assertDownload($base.'_Attendance_2026-09.pdf');
        $this->actingAs($teacher)->get("/classes/{$class->id}/report.pdf?period=week&date=2026-09-03")
            ->assertOk()->assertDownload($base.'_Attendance_2026-08-31_to_2026-09-06.pdf');
    }

    public function test_fr_rpt_02_period_is_shown_in_the_semester_line(): void
    {
        ['teacher' => $t, 'class' => $c] = $this->scenario();

        $header = $this->actingAs($t)->get("/classes/{$c->id}/report")->viewData('header');
        $this->assertArrayNotHasKey('Period', $header);
        $this->assertSame('1st semester (Aug 1, 2026 – Dec 15, 2026)', $header['Semester']);

        $week = $this->actingAs($t)->get("/classes/{$c->id}/report?period=week&date=2026-09-03")->viewData('header');
        $this->assertSame('1st semester · Aug 31 – Sep 6, 2026', $week['Semester']);

        // Same header in the PDF: no "Period:" row.
        $html = view('pdf.attendance-report', $this->actingAs($t)->get("/classes/{$c->id}/report")->original->getData())->render();
        $this->assertStringNotContainsString('Period:', $html);
        $this->assertStringContainsString('<td class="k">Semester:</td><td>1st semester (Aug 1, 2026 – Dec 15, 2026)</td>', $html);
    }

    public function test_fr_rpt_04_report_is_an_attendance_sheet_grid_by_date(): void
    {
        ['teacher' => $t, 'class' => $c, 'alice' => $alice, 'bob' => $bob] = $this->scenario();
        // Semester sessions held: Sep 1 (Alice P, Bob A) and Sep 8 (Alice L, Bob no record → A).

        $res = $this->actingAs($t)->get("/classes/{$c->id}/report")->assertOk();

        $columns = $res->viewData('columns');
        $this->assertSame(['Sep 1', 'Sep 8'], $columns->pluck('date')->all());           // in date order, held sessions only
        $rows = collect($res->viewData('rows'))->keyBy(fn ($r) => $r['student']->name);
        $this->assertSame(['P', 'L'], array_values($rows['Alice Aquino']['marks']));
        $this->assertSame(['A', 'A'], array_values($rows['Bob Bautista']['marks']));      // missing record counts as Absent

        $res->assertSeeInOrder(['Sep 1', 'Sep 8', 'Present', 'Late', 'Absent'])
            ->assertDontSee('>Rate</th>', false)->assertDontSee('Rate = ', false)                 // no rate column
            ->assertSee('data-mark="P"', false)->assertSee('data-mark="L"', false)->assertSee('data-mark="A"', false)
            ->assertSee('Tuesday, Sep 1, 2026', false);                                    // full date on hover

        // Same grid in the PDF download.
        $html = view('pdf.attendance-report', $res->original->getData())->render();
        $this->assertStringContainsString('Sep 1<small>Tue</small>', $html);
        $this->assertStringNotContainsString('>Rate</th>', $html);
        $this->assertStringContainsString('<th>Student No.</th>', $html);
        $this->assertSame(2, substr_count($html, 'class="m m-A"'));
        $this->assertStringContainsString('P = Present', $html);
    }

    public function test_fr_rpt_04_two_sessions_on_one_day_and_long_periods_split_in_downloads(): void
    {
        $teacher = User::factory()->create();
        $class = $this->makeClass($teacher, ['semester_start' => '2026-08-01', 'semester_end' => '2026-12-15']);
        $student = $this->enroll($class);

        // 18 sessions: two on Sep 1, then one a day.
        foreach (['2026-09-01', '2026-09-01', ...array_map(fn ($d) => sprintf('2026-09-%02d', $d), range(2, 17))] as $date) {
            AttendanceSession::factory()->for($class, 'schoolClass')->create(['date' => $date, 'time_in_opened_at' => now()]);
        }

        $res = $this->actingAs($teacher)->get("/classes/{$class->id}/report")->assertOk();
        $this->assertSame(['Sep 1 #1', 'Sep 1 #2', 'Sep 2'], $res->viewData('columns')->take(3)->pluck('date')->all());
        $this->assertCount(18, $res->viewData('columns'));

        // Printed (portrait): 8 dates per table, totals on the last one.
        $html = view('pdf.attendance-report', $res->original->getData())->render();
        $this->assertStringContainsString('Dates 1–8 of 18', $html);
        $this->assertStringContainsString('Dates 9–16 of 18', $html);
        $this->assertStringContainsString('Dates 17–18 of 18', $html);
        $this->assertStringContainsString('Sep 1<small>Tue #2</small>', $html); // "#n" goes under the date
        $this->assertSame(1, substr_count($html, '<th class="c totals">P</th>'));

        $pdf = $this->actingAs($teacher)->get("/classes/{$class->id}/report.pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_report_shows_each_students_section_on_screen_and_in_downloads(): void
    {
        ['teacher' => $t, 'class' => $c, 'alice' => $alice, 'bob' => $bob] = $this->scenario();
        $alice->update(['section' => 'BSIT-2A']);
        $bob->update(['section' => null]);

        $this->actingAs($t)->get("/classes/{$c->id}/report")->assertOk()
            ->assertSeeInOrder(['<th>Section</th>', 'Aquino, Alice', 'BSIT-2A', 'Bautista, Bob', '—'], false);

        $html = view('pdf.attendance-report', $this->actingAs($t)->get("/classes/{$c->id}/report")->original->getData())->render();
        $this->assertStringContainsString('<th>Section</th>', $html);
        $this->assertStringContainsString('<td>BSIT-2A</td>', $html);
    }

    /** The report downloads as a PDF only (the Word download was removed). */
    public function test_fr_rpt_03_report_downloads_as_pdf_teacher_only(): void
    {
        ['teacher' => $t, 'class' => $c, 'alice' => $alice] = $this->scenario();

        $page = $this->actingAs($t)->get("/classes/{$c->id}/report")->assertOk();
        $page->assertSee('Download PDF')->assertDontSee('Download .docx');

        $pdf = $this->actingAs($t)->get("/classes/{$c->id}/report.pdf")->assertOk();
        $pdf->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->actingAs($t)->get("/classes/{$c->id}/report.docx")->assertNotFound();
        $this->actingAs($alice)->get("/classes/{$c->id}/report.pdf")->assertForbidden();
    }

    public function test_student_class_page_shows_only_their_own_attendance(): void
    {
        ['class' => $c, 'alice' => $alice] = $this->scenario();

        $res = $this->actingAs($alice)->get("/classes/{$c->id}")->assertOk()
            ->assertSee('My attendance this semester')
            ->assertDontSee('Bob Bautista')->assertDontSee('2023-00002');

        $this->assertSame([1, 1, 0, 100.0], [$res->viewData('mine')['present'], $res->viewData('mine')['late'], $res->viewData('mine')['absent'], $res->viewData('mine')['rate']]);
        $this->assertSame(2, $res->viewData('held'));
    }

    public function test_assessments_and_review_are_gone(): void
    {
        ['teacher' => $t, 'class' => $c] = $this->scenario();

        foreach (["/classes/{$c->id}/review", "/classes/{$c->id}/review.csv", "/classes/{$c->id}/assessments", '/assessments/1', '/submissions/1/photo'] as $url) {
            $this->actingAs($t)->get($url)->assertNotFound();
        }

        $this->assertFalse(Schema::hasTable('assessments'));
        $this->assertFalse(Schema::hasTable('submissions'));
        $this->assertFalse(Schema::hasColumn('classes', 'sheet_token'));

        $this->actingAs($t)->get("/classes/{$c->id}")->assertOk()->assertDontSee('Assessments')->assertDontSee('>Review<', false);
    }
}
