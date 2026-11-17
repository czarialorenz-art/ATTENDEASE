<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\ScanFlag;
use App\Models\ScanLog;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\QrTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** FR-ATT-16: edit or archive a session; archived sessions are deleted after one year. */
class SessionArchiveTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;
    private User $teacher;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->freezeManila('2026-11-14 09:00:00');
        $this->teacher = User::factory()->create();
        $this->class = $this->makeClass($this->teacher, ['semester_start' => '2026-08-01', 'semester_end' => '2026-12-15']);
        $this->student = $this->enroll($this->class);
    }

    private function makeSession(array $attrs = []): AttendanceSession
    {
        return AttendanceSession::factory()->for($this->class, 'schoolClass')->create($attrs);
    }

    public function test_sessions_list_has_an_edit_menu_with_edit_and_archive(): void
    {
        $s = $this->makeSession(['title' => 'WEEK 1']);

        $this->actingAs($this->teacher)->get("/classes/{$this->class->id}/sessions")->assertOk()
            ->assertSee('Edit session')->assertSee(route('sessions.edit', $s), false)
            ->assertSee(route('sessions.archive', $s), false)
            ->assertSee('deleted for good on Nov 14, 2027', false);
    }

    public function test_edit_changes_title_grace_and_date_before_it_starts(): void
    {
        $s = $this->makeSession(['date' => '2026-11-14', 'title' => 'WEEK 1.2', 'grace_minutes' => 15]);

        $this->actingAs($this->teacher)->get("/sessions/{$s->id}/edit")->assertOk()->assertSee('WEEK 1.2');
        $this->actingAs($this->teacher)->put("/sessions/{$s->id}", ['from_edit' => 1, 'date' => '2026-11-20', 'title' => 'Week 2', 'grace_minutes' => 10])
            ->assertRedirect(route('sessions.index', $this->class))->assertSessionHasNoErrors();

        $s->refresh();
        $this->assertSame(['2026-11-20', 'Week 2', 10], [$s->date->toDateString(), $s->title, $s->grace_minutes]);
    }

    public function test_date_is_locked_once_time_in_has_opened(): void
    {
        $s = $this->makeSession(['date' => '2026-11-14', 'time_in_opened_at' => now()]);

        $this->actingAs($this->teacher)->get("/sessions/{$s->id}/edit")->assertOk()->assertSee("can't change after Time In", false);
        $this->actingAs($this->teacher)->put("/sessions/{$s->id}", ['date' => '2026-11-20', 'title' => 'x', 'grace_minutes' => 15])
            ->assertSessionHasErrors('date');
        $this->assertSame('2026-11-14', $s->fresh()->date->toDateString());

        // Same date (or none) still lets the title and grace change.
        $this->actingAs($this->teacher)->put("/sessions/{$s->id}", ['date' => '2026-11-14', 'title' => 'Renamed', 'grace_minutes' => 20])
            ->assertSessionHasNoErrors();
        $this->assertSame('Renamed', $s->fresh()->title);
    }

    public function test_archiving_hides_the_session_everywhere_but_keeps_it_restorable(): void
    {
        $s = $this->makeSession(['date' => '2026-11-14', 'title' => 'WEEK 1.2', 'time_in_opened_at' => now()->subHour(), 'time_in_closed_at' => now()]);
        AttendanceRecord::factory()->create(['attendance_session_id' => $s->id, 'student_id' => $this->student->id, 'status' => 'absent']);

        $this->actingAs($this->teacher)->post("/sessions/{$s->id}/archive")
            ->assertRedirect(route('sessions.index', $this->class))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'deleted for good on Nov 14, 2027'));

        $s->refresh();
        $this->assertTrue($s->isArchived());
        $this->assertSame('2027-11-14', $s->deletesOn()->toDateString());

        // Gone from the active list, the dashboard, the report and the student's views.
        $this->actingAs($this->teacher)->get("/classes/{$this->class->id}/sessions")->assertDontSee('WEEK 1.2');
        $this->actingAs($this->teacher)->get('/dashboard')->assertDontSee('WEEK 1.2');
        $this->assertSame(0, $this->actingAs($this->teacher)->get("/classes/{$this->class->id}/report")->viewData('held'));
        $this->actingAs($this->student)->get('/my/attendance')->assertDontSee('WEEK 1.2');
        $this->assertSame(0, $this->actingAs($this->student)->get("/classes/{$this->class->id}")->viewData('held'));

        // Shown under Archived, read-only, with its deletion date.
        $this->actingAs($this->teacher)->get("/classes/{$this->class->id}/sessions?archived=1")
            ->assertSee('WEEK 1.2')->assertSee('Nov 14, 2027')->assertSee('Restore');
        $this->actingAs($this->teacher)->get("/sessions/{$s->id}")->assertOk()
            ->assertSee('Archived on Nov 14, 2026')->assertSee('Restore session')
            ->assertDontSee('Open Time In')->assertDontSee('Session settings');

        // Restore brings it all back.
        $this->actingAs($this->teacher)->post("/sessions/{$s->id}/restore")->assertRedirect();
        $this->assertFalse($s->fresh()->isArchived());
        $this->assertSame(1, $this->actingAs($this->teacher)->get("/classes/{$this->class->id}/report")->viewData('held'));
    }

    public function test_archived_session_cannot_be_used(): void
    {
        $s = $this->makeSession(['date' => '2026-11-14']);
        $record = AttendanceRecord::factory()->create(['attendance_session_id' => $s->id, 'student_id' => $this->student->id]);
        $flag = ScanFlag::factory()->create(['scan_log_id' => ScanLog::factory()->create(['attendance_record_id' => $record->id])->id]);
        $token = app(QrTokenService::class)->issue($s, 'time_in');
        $s->delete();

        $this->actingAs($this->teacher)->post("/sessions/{$s->id}/stages/time_in/open")->assertNotFound();
        $this->actingAs($this->teacher)->get("/sessions/{$s->id}/edit")->assertNotFound();
        $this->actingAs($this->teacher)->post("/sessions/{$s->id}/records", ['student_id' => $this->student->id, 'status' => 'late'])->assertNotFound();
        $this->actingAs($this->teacher)->post("/flags/{$flag->id}/clear")->assertForbidden();
        $this->assertNull(app(QrTokenService::class)->verify($token));
    }

    public function test_archived_session_can_be_deleted_for_good_right_away(): void
    {
        $s = $this->makeSession(['title' => 'Mistake Session']);
        $record = AttendanceRecord::factory()->create(['attendance_session_id' => $s->id, 'student_id' => $this->student->id]);
        ScanFlag::factory()->create(['scan_log_id' => ScanLog::factory()->create(['attendance_record_id' => $record->id])->id]);
        $s->delete();

        $this->actingAs($this->teacher)->get("/classes/{$this->class->id}/sessions?archived=1")
            ->assertSee(route('sessions.destroy', $s), false)->assertSee("This can't be undone.", false);
        $this->actingAs($this->teacher)->get("/sessions/{$s->id}")->assertSee('>Delete</button>', false);

        $this->actingAs($this->teacher)->delete("/sessions/{$s->id}")
            ->assertRedirect(route('sessions.index', [$this->class, 'archived' => 1]))
            ->assertSessionHas('status', 'The Nov 14, 2026 session was deleted for good.');

        $this->assertNull(AttendanceSession::withTrashed()->find($s->id));
        $this->assertDatabaseMissing('attendance_records', ['id' => $record->id]);
        $this->assertSame(0, ScanFlag::count());
    }

    public function test_only_archived_sessions_can_be_deleted_and_only_by_the_teacher(): void
    {
        $active = $this->makeSession();
        $this->actingAs($this->teacher)->delete("/sessions/{$active->id}")->assertNotFound();
        $this->assertNotNull(AttendanceSession::find($active->id));

        $archived = $this->makeSession();
        $archived->delete();
        foreach ([$this->student, User::factory()->create()] as $user) {
            $this->actingAs($user)->delete("/sessions/{$archived->id}")->assertForbidden();
        }
        $this->assertNotNull(AttendanceSession::withTrashed()->find($archived->id));

        // Active sessions never show a Delete button.
        $this->actingAs($this->teacher)->get("/classes/{$this->class->id}/sessions")
            ->assertDontSee('btn-danger btn-sm">Delete', false)->assertDontSee('value="DELETE"', false);
    }

    public function test_search_finds_active_sessions_by_title_or_date(): void
    {
        $this->makeSession(['date' => '2026-11-12', 'title' => 'WEEK 1']);
        $this->makeSession(['date' => '2026-11-13', 'title' => 'week 2']);
        $this->makeSession(['date' => '2026-10-05', 'title' => 'Orientation']);
        $this->makeSession(['date' => '2026-11-13', 'title' => 'Hidden Archived'])->delete();

        $titles = fn (string $q, bool $archived = false) => $this->actingAs($this->teacher)
            ->get("/classes/{$this->class->id}/sessions?".http_build_query(array_filter(['q' => $q, 'archived' => $archived ? 1 : null])))
            ->assertOk()->viewData('sessions')->pluck('title')->sort()->values()->all();

        $this->assertSame(['WEEK 1', 'week 2'], $titles('week'));          // case-insensitive title
        $this->assertSame(['week 2'], $titles('week 2'));
        $this->assertSame(['week 2'], $titles('Nov 13'));                 // readable date, any year
        $this->assertSame(['week 2'], $titles('November 13, 2026'));
        $this->assertSame(['week 2'], $titles('11/13/2026'));
        $this->assertSame(['WEEK 1'], $titles('2026-11-12'));             // ISO date
        $this->assertSame(['WEEK 1', 'week 2'], $titles('2026-11'));      // a whole month
        $this->assertSame([], $titles('nothing like this'));
        $this->assertSame([], $titles('50%'));                            // % is a literal, not a wildcard

        // Archived sessions never show in the active search.
        $this->assertNotContains('Hidden Archived', $titles('Nov 13'));
    }

    public function test_search_works_in_the_archived_view_and_carries_over(): void
    {
        $this->makeSession(['date' => '2026-11-13', 'title' => 'Old Quiz Day'])->delete();
        $this->makeSession(['date' => '2026-11-12', 'title' => 'Old Lab'])->delete();
        $this->makeSession(['date' => '2026-11-13', 'title' => 'Active One']);

        $res = $this->actingAs($this->teacher)->get("/classes/{$this->class->id}/sessions?archived=1&q=quiz")->assertOk();
        $this->assertSame(['Old Quiz Day'], $res->viewData('sessions')->pluck('title')->all());
        $res->assertSee('1 result for', false)
            ->assertSee('name="archived" value="1"', false)                                        // stays in Archived
            ->assertSee(route('sessions.index', [$this->class, 'q' => 'quiz']), false);             // Active link keeps the search

        $this->actingAs($this->teacher)->get("/classes/{$this->class->id}/sessions?archived=1&q=zzz")
            ->assertSee('No archived sessions match', false);
    }

    public function test_fr_att_19_sessions_outside_the_semester_are_blocked_with_a_fix_link(): void
    {
        // Semester for this class: Aug 1 – Dec 15, 2026.
        $url = "/classes/{$this->class->id}/sessions";

        $this->actingAs($this->teacher)->post($url, ['date' => '2026-12-16'])
            ->assertSessionHasErrors(['date' => "This date is after the semester ends (Dec 15, 2026); it wouldn't count in semester totals. Update the semester dates first."]);
        $this->actingAs($this->teacher)->post($url, ['date' => '2026-07-31'])
            ->assertSessionHasErrors(['date' => "This date is before the semester starts (Aug 1, 2026); it wouldn't count in semester totals. Update the semester dates first."]);
        $this->assertSame(0, AttendanceSession::count());

        // First and last day are fine.
        $this->actingAs($this->teacher)->post($url, ['date' => '2026-08-01'])->assertSessionHasNoErrors();
        $this->actingAs($this->teacher)->post($url, ['date' => '2026-12-15'])->assertSessionHasNoErrors();

        // The form limits the picker and has the live warning with a link to fix the dates.
        $this->actingAs($this->teacher)->get($url)->assertOk()
            ->assertSee('min="2026-08-01"', false)->assertSee('max="2026-12-15"', false)
            ->assertSee('id="semester-warning"', false)
            ->assertSee(route('classes.edit', $this->class), false)
            ->assertSee('Semester: Aug 1, 2026 – Dec 15, 2026');
    }

    public function test_fr_att_19_a_date_change_must_stay_inside_the_semester(): void
    {
        $s = $this->makeSession(['date' => '2026-11-14']);

        $this->actingAs($this->teacher)->put("/sessions/{$s->id}", ['from_edit' => 1, 'date' => '2026-12-20', 'title' => 'x', 'grace_minutes' => 15])
            ->assertSessionHasErrors('date');
        $this->assertSame('2026-11-14', $s->fresh()->date->toDateString());
    }

    public function test_fr_att_19_existing_sessions_outside_the_semester_are_marked(): void
    {
        // e.g. the semester end was moved earlier after the session was made.
        $s = $this->makeSession(['date' => '2026-11-14', 'title' => 'Late Week', 'time_in_opened_at' => now()]);
        $this->class->update(['semester_end' => '2026-11-13']);

        $this->actingAs($this->teacher)->get("/classes/{$this->class->id}/sessions")->assertSee('Outside semester');
        $this->actingAs($this->teacher)->get("/sessions/{$s->id}")->assertOk()
            ->assertSee('This date is after the semester ends (Nov 13, 2026).')->assertSee('Fix semester dates');

        // Its title and grace can still be edited (the date isn't changing).
        $this->actingAs($this->teacher)->put("/sessions/{$s->id}", ['date' => '2026-11-14', 'title' => 'Renamed', 'grace_minutes' => 10])
            ->assertSessionHasNoErrors();
        $this->assertSame('Renamed', $s->fresh()->title);
    }

    public function test_a_session_with_an_open_stage_cannot_be_archived(): void
    {
        $s = $this->makeSession(['date' => '2026-11-14', 'time_in_opened_at' => now()]);

        $this->actingAs($this->teacher)->post("/sessions/{$s->id}/archive")->assertSessionHasErrors('stage');
        $this->assertFalse($s->fresh()->isArchived());
    }

    public function test_only_the_classs_teacher_can_edit_archive_or_restore(): void
    {
        $s = $this->makeSession();
        $archived = $this->makeSession(['title' => 'old']);
        $archived->delete();

        foreach ([$this->student, User::factory()->create()] as $user) {
            $this->actingAs($user)->get("/sessions/{$s->id}/edit")->assertForbidden();
            $this->actingAs($user)->post("/sessions/{$s->id}/archive")->assertForbidden();
            $this->actingAs($user)->post("/sessions/{$archived->id}/restore")->assertForbidden();
            $this->actingAs($user)->get("/sessions/{$archived->id}")->assertForbidden();
        }
        $this->assertFalse($s->fresh()->isArchived());
        $this->assertTrue(AttendanceSession::withTrashed()->find($archived->id)->isArchived());
    }

    public function test_archived_sessions_are_deleted_for_good_after_one_year(): void
    {
        $old = $this->makeSession(['title' => 'old']);
        $recent = $this->makeSession(['title' => 'recent']);
        $active = $this->makeSession(['title' => 'active']);
        $record = AttendanceRecord::factory()->create(['attendance_session_id' => $old->id, 'student_id' => $this->student->id]);
        ScanFlag::factory()->create(['scan_log_id' => ScanLog::factory()->create(['attendance_record_id' => $record->id])->id]);

        $old->delete();
        $this->travel(200)->days();
        $recent->delete();
        $this->travel(166)->days(); // old: archived 366 days ago; recent: 166 days ago

        Artisan::call('model:prune', ['--model' => [AttendanceSession::class]]);

        $this->assertNull(AttendanceSession::withTrashed()->find($old->id));
        $this->assertDatabaseMissing('attendance_records', ['id' => $record->id]); // its records, scans and flags go too
        $this->assertSame(0, ScanFlag::count());
        $this->assertNotNull(AttendanceSession::withTrashed()->find($recent->id));
        $this->assertNotNull(AttendanceSession::find($active->id));
    }

    public function test_sessions_page_also_cleans_up_when_the_scheduler_isnt_running(): void
    {
        $old = $this->makeSession(['title' => 'Expired Week Zero']);
        $old->delete();
        $this->travel(366)->days();

        $this->actingAs($this->teacher)->get("/classes/{$this->class->id}/sessions?archived=1")->assertOk()
            ->assertDontSee('Expired Week Zero')->assertSee('No archived sessions.');
        $this->assertNull(AttendanceSession::withTrashed()->find($old->id));
    }

    public function test_daily_prune_is_scheduled(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());

        $this->assertTrue($events->contains(fn ($e) => str_contains($e->command, 'model:prune') && str_contains($e->command, 'AttendanceSession')));
    }
}
