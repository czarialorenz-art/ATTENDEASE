<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportController;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * FR-RPT-05 (status filter applies to the whole table), FR-RPT-06 (one-student record),
 * FR-RPT-07 (Week From–To, Month whole or custom).
 */
class ReportFiltersTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;
    private User $teacher;
    private array $s = [];
    private array $u = [];

    /**
     *            Nov 2 | Nov 16 | Nov 17 #1 | Nov 17 #2 | Nov 18 | Nov 23
     * Loreno      P    |   A    |    L      |    P      |   A    |   P
     * Lim         P    |   P    |    P      |    P      |   L    |   P
     * Uy          A    |   L    |    A      |   (none)  |   P    |   P
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeManila('2026-11-24 09:00:00');
        $this->teacher = User::factory()->teacher()->create(['name' => 'Prof. Ana Reyes']);
        $this->class = $this->makeClass($this->teacher, [
            'subject' => 'ITE101', 'section' => 'BSIT-4B', 'school_term' => '2026-2027', 'semester' => '1st',
            'semester_start' => '2026-08-01', 'semester_end' => '2026-12-15',
        ]);
        $this->u = [
            'loreno' => $this->enroll($this->class, User::factory()->create(['name' => 'Maria Teresa Loreno', 'id_number' => '2023-00001', 'course' => 'BSIT', 'year_level' => 4, 'section' => 'B'])),
            'lim' => $this->enroll($this->class, User::factory()->create(['name' => 'Ben Lim', 'id_number' => '2023-00002'])),
            'uy' => $this->enroll($this->class, User::factory()->create(['name' => 'Cara Uy', 'id_number' => '2023-00003'])),
        ];
        $held = fn ($date, $at, $title = null) => AttendanceSession::factory()->for($this->class, 'schoolClass')
            ->create(['date' => $date, 'title' => $title, 'time_in_opened_at' => $date.' '.$at]);
        $this->s = [
            'n2' => $held('2026-11-02', '08:00'), 'n16' => $held('2026-11-16', '08:00'),
            'n17a' => $held('2026-11-17', '08:00', 'Lecture'), 'n17b' => $held('2026-11-17', '13:00', 'Lab'),
            'n18' => $held('2026-11-18', '08:00'), 'n23' => $held('2026-11-23', '08:00'),
        ];
        $marks = [
            'loreno' => ['present', 'absent', 'late', 'present', 'absent', 'present'],
            'lim' => ['present', 'present', 'present', 'present', 'late', 'present'],
            'uy' => ['absent', 'late', 'absent', null, 'present', 'present'],
        ];
        foreach ($marks as $who => $list) {
            foreach (array_values($this->s) as $i => $session) {
                if ($list[$i]) {
                    AttendanceRecord::factory()->create(['attendance_session_id' => $session->id, 'student_id' => $this->u[$who]->id,
                        'status' => $list[$i], 'time_in_at' => $session->date->format('Y-m-d').' 08:05', 'time_out_at' => $session->date->format('Y-m-d').' 10:00']);
                }
            }
        }
    }

    private function report(string $query)
    {
        return $this->actingAs($this->teacher)->get("/classes/{$this->class->id}/report?{$query}");
    }

    /** The same data the PDF route builds for a query. */
    private function pdfData(string $query): array
    {
        return (new \ReflectionMethod(ReportController::class, 'data'))
            ->invoke(app(ReportController::class), Request::create("/classes/{$this->class->id}/report.pdf?{$query}"), $this->class);
    }

    private const PERIODS = [
        'day' => 'period=day&date=2026-11-17',
        'week' => 'period=week&from=2026-11-16&to=2026-11-20',
        'whole month' => 'period=month&month_mode=whole&month=2026-11',
        'custom month' => 'period=month&month_mode=custom&from=2026-11-16&to=2026-11-18',
        'semester' => 'period=semester',
    ];

    /* ---------- FR-RPT-05: the status filter applies to the whole table ---------- */

    public function test_fr_rpt_05_absent_filter_hides_other_marks_and_totals(): void
    {
        $res = $this->report('period=month&month_mode=whole&month=2026-11&status=absent')->assertOk();

        // Lim has no Absent: left out. Uy has 3 (one is a held session with no record), Loreno 2.
        $this->assertSame(['Cara Uy', 'Maria Teresa Loreno'], collect($res->viewData('rows'))->pluck('student.name')->all());
        $res->assertSee('Showing 2 of 3 students with at least one Absent')
            ->assertSee('data-total-column>Absent', false)
            ->assertDontSee('<th class="text-center">Late</th>', false);

        // Loreno's Nov 17 #2 "P" is not shown: only A marks and "–".
        $html = $res->getContent();
        preg_match_all('/data-mark="([^"]+)"/', $html, $m);
        $this->assertNotContains('P', $m[1]);
        $this->assertNotContains('L', $m[1]);
    }

    public function test_fr_rpt_05_every_period_and_status_combination(): void
    {
        foreach (self::PERIODS as $label => $query) {
            $all = $this->report($query)->assertOk();
            $allRows = collect($all->viewData('rows'));
            $this->assertCount(3, $allRows, $label);

            foreach (['present' => 'P', 'late' => 'L', 'absent' => 'A'] as $status => $mark) {
                $res = $this->report($query.'&status='.$status)->assertOk();
                $rows = collect($res->viewData('rows'));

                // Exactly the students with at least one such mark in the period, most first.
                $expected = $allRows->filter(fn ($r) => $r[$status] > 0)->sortByDesc($status)->pluck('student.name')->values()->all();
                $this->assertEqualsCanonicalizing($expected, $rows->pluck('student.name')->all(), "$label / $status");
                $counts = $rows->pluck($status)->all();
                $sorted = $counts;
                rsort($sorted);
                $this->assertSame($sorted, $counts, "$label / $status sorted");

                // Only that mark in the cells.
                preg_match_all('/data-mark="([^"]+)"/', $res->getContent(), $m);
                $this->assertEmpty(array_diff(array_unique($m[1]), [$mark, '-']), "$label / $status cells");
                $res->assertSee('Showing '.$rows->count().' of 3 students with at least one '.ucfirst($status));

                // Screen and PDF: same students and counts.
                $pdf = $this->pdfData($query.'&status='.$status);
                $this->assertSame($rows->map(fn ($r) => [$r['student']->id, $r[$status]])->all(),
                    collect($pdf['rows'])->map(fn ($r) => [$r['student']->id, $r[$status]])->all(), "$label / $status PDF");
            }
        }
    }

    public function test_fr_rpt_05_search_and_status_work_together_and_are_kept(): void
    {
        $res = $this->report('period=week&from=2026-11-16&to=2026-11-20&status=late&q=2023-0000')->assertOk();
        $this->assertSame(['Ben Lim', 'Cara Uy', 'Maria Teresa Loreno'], collect($res->viewData('rows'))->pluck('student.name')->sort()->values()->all());
        $res->assertSee('value="2023-0000"', false)->assertSee('<option value="late" selected>', false)
            ->assertSee('value="2026-11-16"', false)->assertSee('value="2026-11-20"', false)
            ->assertSee('href="'.route('reports.show', $this->class).'" class="btn-secondary">Clear', false);

        $html = view('pdf.attendance-report', $this->pdfData('period=week&from=2026-11-16&to=2026-11-20&status=late&q=2023-0000'))->render();
        $this->assertStringContainsString('Status: Late · Search: 2023-0000 · 3 of 3 students with at least one Late', $html);
        $this->assertStringContainsString('Weekly Attendance Summary · Nov 16–20, 2026', $html);
    }

    /* ---------- FR-RPT-07: period pickers ---------- */

    public function test_fr_rpt_07_week_from_to(): void
    {
        $res = $this->report('period=week&from=2026-11-16&to=2026-11-20')->assertOk();
        $this->assertSame(4, $res->viewData('held')); // Nov 16, 17 ×2, 18
        $res->assertSee('Weekly Attendance Summary · Nov 16–20, 2026');

        // Default: Monday to Sunday of this week (today Tue Nov 24).
        $default = $this->report('period=week')->assertOk();
        $this->assertSame(['2026-11-23', '2026-11-29'], [$default->viewData('from')->toDateString(), $default->viewData('to')->toDateString()]);

        // Only From: To is 6 days later.
        $this->assertSame('2026-11-22', $this->report('period=week&from=2026-11-16')->viewData('to')->toDateString());

        $this->report('period=week&from=2026-11-16&to=2026-11-23')->assertSessionHasErrors(['to' => 'A week can be at most 7 days: the To date must be on or before Nov 22, 2026.']);
        $this->report('period=week&from=2026-11-16&to=2026-11-15')->assertSessionHasErrors(['to' => "The To date can't be before the From date."]);
        $this->report('period=week&from=nope')->assertSessionHasErrors('from');
    }

    public function test_fr_rpt_07_month_whole_and_custom(): void
    {
        $whole = $this->report('period=month&month_mode=whole&month=2026-11')->assertOk();
        $this->assertSame(6, $whole->viewData('held'));
        $whole->assertSee('Monthly Attendance Summary · November 2026')->assertSee('value="2026-11"', false);
        $this->assertSame(0, $this->report('period=month&month=2026-10')->viewData('held')); // whole is the default mode

        $custom = $this->report('period=month&month_mode=custom&from=2026-11-16&to=2026-11-18')->assertOk();
        $this->assertSame(4, $custom->viewData('held'));
        $custom->assertSee('Monthly Attendance Summary · Nov 16–18, 2026');

        $this->report('period=month&month_mode=custom&from=2026-11-25&to=2026-12-02')
            ->assertSessionHasErrors(['to' => 'A custom range must be inside one month: pick a To date in November 2026.']);
        $this->report('period=month&month_mode=custom&from=2026-11-20&to=2026-11-10')->assertSessionHasErrors('to');
        $this->report('period=month&month=2026-13')->assertSessionHasErrors('month');
        $this->report('period=month&month_mode=weekly')->assertSessionHasErrors('month_mode');
    }

    public function test_fr_rpt_07_fields_follow_the_period_and_the_pdf_uses_the_range(): void
    {
        // Only the chosen period's fields are enabled (hidden ones are disabled, so they aren't sent).
        $week = $this->report('period=week&from=2026-11-16&to=2026-11-20')->getContent();
        $this->assertMatchesRegularExpression('/name="from" value="2026-11-16" class="input"\s*>/', $week);
        $this->assertMatchesRegularExpression('/name="date" value="[0-9-]+" class="input" disabled>/', $week);
        $this->assertMatchesRegularExpression('/name="month" value="2026-11" class="input"\s*disabled>/', $week);

        $base = 'ITE101_BSIT-4B_Attendance_';
        $pdf = fn ($q) => $this->actingAs($this->teacher)->get("/classes/{$this->class->id}/report.pdf?{$q}")->assertOk();
        $pdf('period=week&from=2026-11-16&to=2026-11-20')->assertDownload($base.'2026-11-16_to_2026-11-20.pdf');
        $pdf('period=month&month_mode=whole&month=2026-11')->assertDownload($base.'2026-11.pdf');
        $pdf('period=month&month_mode=custom&from=2026-11-16&to=2026-11-18')->assertDownload($base.'2026-11-16_to_2026-11-18.pdf');
        $pdf('period=day&date=2026-11-17')->assertDownload($base.'2026-11-17.pdf');

        $this->assertSame(4, $this->pdfData('period=week&from=2026-11-16&to=2026-11-20')['held']);
        $this->assertSame(
            route('reports.pdf', [$this->class, 'period' => 'week', 'from' => '2026-11-16', 'to' => '2026-11-20']),
            route('reports.pdf', [$this->class, ...$this->report('period=week&from=2026-11-16&to=2026-11-20')->viewData('query')]),
        );
    }

    /* ---------- FR-RPT-06: one-student record ---------- */

    public function test_fr_rpt_06_one_search_match_shows_the_student_record(): void
    {
        $res = $this->report('period=month&month_mode=whole&month=2026-11&q=loreno')->assertOk();
        $this->assertSame('Maria Teresa Loreno', $res->viewData('single')['student']->name);
        $res->assertSee('data-student-record', false)->assertDontSee('data-session-column', false)
            ->assertSee('Student Attendance Record · November 2026')
            ->assertSeeInOrder(['Student No.', '2023-00001', 'Name', 'Loreno, Maria Teresa', 'Section', 'BSIT 4-B', 'Subject', 'ITE101 · BSIT-4B', 'School term', '2026-2027 · 1st semester', 'Period', 'November 2026'])
            ->assertSeeInOrder(['Nov 2, 2026', 'Monday', 'Present', '8:05 AM', '10:00 AM', 'Nov 16, 2026', 'Absent', 'Nov 17, 2026', 'Tuesday', 'Lecture', 'Late', 'Nov 17, 2026', 'Lab', 'Present'])
            ->assertSeeInOrder(['Present', '3', 'Late', '1', 'Absent', '2', 'Sessions held', '6', 'Attendance rate', '66.7%']);

        // Two or more matches: the class grid.
        $this->assertNull($this->report('period=month&month=2026-11&q=2023-0000')->assertSee('data-session-column', false)->viewData('single'));
    }

    public function test_fr_rpt_06_picked_student_status_filter_and_pdf(): void
    {
        $id = $this->u['loreno']->id;
        $res = $this->report("period=month&month_mode=whole&month=2026-11&student={$id}&status=absent")->assertOk();
        $this->assertSame(['absent', 'absent'], collect($res->viewData('studentRows'))->pluck('status')->all()); // only the Absent dates
        $res->assertSee('<option value="'.$id.'" selected>', false)->assertDontSee('data-status="present"', false);

        $this->actingAs($this->teacher)->get("/classes/{$this->class->id}/report.pdf?period=month&month_mode=whole&month=2026-11&student={$id}")
            ->assertOk()->assertDownload('ITE101_2023-00001_Loreno_Attendance_2026-11.pdf');
        $html = view('pdf.student-attendance', $this->pdfData("period=week&from=2026-11-16&to=2026-11-20&student={$id}&status=absent"))->render();
        $this->assertStringContainsString('Student Attendance Record · Nov 16–20, 2026', $html);
        $this->assertStringContainsString('Status: Absent dates only', $html);
        $this->assertSame(2, substr_count($html, '<td class="s s-absent">Absent</td>'));

        $this->report('student=999999')->assertSessionHasErrors(['student' => "That student isn't in this class."]);
        $this->report('student='.User::factory()->create()->id)->assertSessionHasErrors('student');
    }

    public function test_reports_stay_teacher_only(): void
    {
        $this->actingAs($this->u['loreno'])->get("/classes/{$this->class->id}/report?q=loreno")->assertForbidden();
        $this->actingAs($this->makeClass()->teacher)->get("/classes/{$this->class->id}/report.pdf?student={$this->u['loreno']->id}")->assertForbidden();
    }
}
