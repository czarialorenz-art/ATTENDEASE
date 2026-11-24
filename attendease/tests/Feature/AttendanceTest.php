<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\SchoolClass;
use App\Models\User;
use App\Notifications\AbsenceAlert;
use App\Services\QrTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;
    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->freezeManila();
        $this->teacher = User::factory()->create();
        $this->class = $this->makeClass($this->teacher, [])->fresh();
    }

    private function makeSession(array $attrs = []): AttendanceSession
    {
        return AttendanceSession::factory()->for($this->class, 'schoolClass')->create($attrs);
    }

    private function token(AttendanceSession $s, string $stage = 'time_in'): string
    {
        return app(QrTokenService::class)->issue($s, $stage);
    }

    private function scan(User $student, string $token, array $extra = [], string $ip = '10.0.0.1')
    {
        return $this->actingAs($student)
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/scan', array_merge(['t' => $token], $extra));
    }

    private function openTimeIn(AttendanceSession $s): void
    {
        $this->actingAs($this->teacher)->post("/sessions/{$s->id}/stages/time_in/open")->assertSessionHasNoErrors();
        $s->refresh();
    }

    /* ---------- Sessions and stages ---------- */

    public function test_fr_att_01_create_session_with_default_grace(): void
    {
        // FR-ATT-20: no date is sent; the server uses today.
        $this->actingAs($this->teacher)->post("/classes/{$this->class->id}/sessions")->assertRedirect();

        $s = AttendanceSession::first();
        $this->assertSame(15, $s->grace_minutes);
        $this->assertSame('2026-11-12', $s->date->toDateString());

        $this->actingAs($this->teacher)->post("/classes/{$this->class->id}/sessions", ['grace_minutes' => -1])->assertSessionHasErrors('grace_minutes');
        $this->actingAs($this->teacher)->post("/classes/{$this->class->id}/sessions", ['grace_minutes' => 0])->assertSessionHasNoErrors();
    }

    public function test_fr_att_01_grace_change_recalculates_qr_time_in_but_not_manual(): void
    {
        $s = $this->makeSession(['grace_minutes' => 15]);
        $this->openTimeIn($s);
        $early = $this->enroll($this->class);
        $later = $this->enroll($this->class);
        $manual = $this->enroll($this->class);

        $this->travel(5)->minutes();
        $this->scan($early, $this->token($s));
        $this->travel(5)->minutes(); // 10 min after open
        $this->scan($later, $this->token($s), [], '10.0.0.2');
        $this->actingAs($this->teacher)->post("/sessions/{$s->id}/records", ['student_id' => $manual->id, 'status' => 'present']);

        $this->assertSame('present', AttendanceRecord::where('student_id', $later->id)->value('status'));

        $this->actingAs($this->teacher)->put("/sessions/{$s->id}", ['grace_minutes' => 7])->assertSessionHasNoErrors();

        $this->assertSame('present', AttendanceRecord::where('student_id', $early->id)->value('status'));
        $this->assertSame('late', AttendanceRecord::where('student_id', $later->id)->value('status'));
        $this->assertSame('present', AttendanceRecord::where('student_id', $manual->id)->value('status'));
    }

    public function test_fr_att_02_only_one_stage_open_and_times_recorded(): void
    {
        $s = $this->makeSession();
        $this->openTimeIn($s);
        $this->assertNotNull($s->time_in_opened_at);

        $this->actingAs($this->teacher)->post("/sessions/{$s->id}/stages/time_out/open")->assertSessionHasErrors('stage');
        $this->assertNull($s->fresh()->time_out_opened_at);

        $this->actingAs($this->teacher)->post("/sessions/{$s->id}/stages/time_in/close");
        $this->actingAs($this->teacher)->post("/sessions/{$s->id}/stages/time_out/open")->assertSessionHasNoErrors();

        $s->refresh();
        $this->assertNotNull($s->time_in_closed_at);
        $this->assertNotNull($s->time_out_opened_at);
        $this->assertSame('time_out', $s->openStage());
    }

    public function test_fr_att_17_opening_time_in_notifies_the_classs_students_once(): void
    {
        $s = $this->makeSession(['title' => 'Week 3', 'grace_minutes' => 15]);
        $approved = $this->enroll($this->class);
        $other = $this->enroll($this->class);
        $pending = $this->enroll($this->class, null, 'pending');
        $outsider = User::factory()->create();

        $this->openTimeIn($s);

        Notification::assertSentTo([$approved, $other], \App\Notifications\TimeInOpened::class, function ($n, $channels) {
            return $channels === ['database', 'mail'];
        });
        Notification::assertNotSentTo([$pending, $outsider, $this->teacher], \App\Notifications\TimeInOpened::class);
        Notification::assertSentTimes(\App\Notifications\TimeInOpened::class, 2);

        // Message: class, title, and the Present deadline (opened 8:00 AM + 15 min).
        $data = (new \App\Notifications\TimeInOpened($s->fresh()))->toArray($approved);
        $this->assertSame('Time In is open: '.$this->class->label(), $data['title']);
        $this->assertStringContainsString('(Week 3)', $data['message']);
        $this->assertStringContainsString('by 8:15 AM to be marked Present', $data['message']);
        $this->assertSame(route('scanner'), $data['url']);

        // Reopening Time In, or opening Time Out, doesn't notify again.
        $this->actingAs($this->teacher)->post("/sessions/{$s->id}/stages/time_in/close");
        $this->actingAs($this->teacher)->post("/sessions/{$s->id}/stages/time_in/open");
        $this->actingAs($this->teacher)->post("/sessions/{$s->id}/stages/time_in/close");
        $this->actingAs($this->teacher)->post("/sessions/{$s->id}/stages/time_out/open");
        Notification::assertSentTimes(\App\Notifications\TimeInOpened::class, 2);
    }

    public function test_fr_att_17_a_failed_open_notifies_no_one(): void
    {
        $student = $this->enroll($this->class);
        $tomorrow = $this->makeSession(['date' => '2026-11-13']);

        $this->actingAs($this->teacher)->post("/sessions/{$tomorrow->id}/stages/time_in/open")->assertSessionHasErrors('stage');

        Notification::assertNotSentTo($student, \App\Notifications\TimeInOpened::class);
    }

    public function test_fr_att_03_stage_opens_only_on_session_date(): void
    {
        $tomorrow = $this->makeSession(['date' => '2026-11-13']);
        $yesterday = $this->makeSession(['date' => '2026-11-11']);

        $this->actingAs($this->teacher)->post("/sessions/{$tomorrow->id}/stages/time_in/open")->assertSessionHasErrors('stage');
        $this->actingAs($this->teacher)->post("/sessions/{$yesterday->id}/stages/time_in/open")->assertSessionHasErrors('stage');
        $this->assertNull($tomorrow->fresh()->time_in_opened_at);

        // 23:59 Manila on the 12th is still the session date, even though it's 15:59 UTC.
        $this->freezeManila('2026-11-12 23:59:00');
        $today = $this->makeSession(['date' => '2026-11-12']);
        $this->openTimeIn($today);
        $this->assertNotNull($today->time_in_opened_at);
    }

    /* ---------- QR ---------- */

    public function test_fr_att_04_live_screen_shows_qr_and_count(): void
    {
        $s = $this->makeSession();
        $this->openTimeIn($s);
        $this->scan($this->enroll($this->class), $this->token($s));

        $json = $this->actingAs($this->teacher)->getJson("/sessions/{$s->id}/live/state")->assertOk()->json();

        $this->assertSame('time_in', $json['stage']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $json['qr']);
        $this->assertStringContainsString('/scan?t=', $json['url']);
        $this->assertSame(1, $json['count']);
        $this->assertSame(1, $json['total']);

        $this->actingAs($this->teacher)->get("/sessions/{$s->id}/live")->assertOk()->assertSee('students scanned');
    }

    public function test_fr_att_05_token_accepts_current_and_previous_window_only(): void
    {
        $s = $this->makeSession();
        $this->openTimeIn($s);
        $token = $this->token($s); // issued at 08:00:00
        $student = $this->enroll($this->class);
        $qr = app(QrTokenService::class);

        $this->travel(59)->seconds();
        $this->assertNotNull($qr->verify($token));

        $this->travel(2)->seconds(); // 61 s old
        $this->assertNull($qr->verify($token));
        $this->scan($student, $token)->assertStatus(410)->assertJson(['title' => 'This QR code has expired.']);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_fr_att_05_tampered_wrong_date_or_closed_stage_tokens_are_rejected(): void
    {
        $qr = app(QrTokenService::class);
        $s = $this->makeSession();
        $this->openTimeIn($s);
        $token = $this->token($s);

        [$payload, $sig] = explode('.', $token);
        $forged = rtrim(strtr(base64_encode(json_encode(['s' => $s->id, 'g' => 'time_out', 'i' => now()->getTimestamp()])), '+/', '-_'), '=').'.'.$sig;
        $this->assertNull($qr->verify($forged));
        $this->assertNull($qr->verify('garbage'));

        // Closed stage.
        $this->actingAs($this->teacher)->post("/sessions/{$s->id}/stages/time_in/close");
        $this->assertNull($qr->verify($token));

        // Another date: a session dated yesterday with an (artificially) open stage.
        $old = $this->makeSession(['date' => '2026-11-11', 'time_in_opened_at' => now()]);
        $this->assertNull($qr->verify($qr->issue($old, 'time_in')));

        $this->actingAs($this->enroll($this->class))->get('/scan?t='.urlencode($token))->assertSee('This QR code has expired.');
    }

    /* ---------- Scanning ---------- */

    public function test_fr_att_06_only_approved_students_can_record(): void
    {
        $s = $this->makeSession();
        $this->openTimeIn($s);
        $token = $this->token($s);

        $this->scan(User::factory()->create(), $token)->assertForbidden();
        $this->scan($this->enroll($this->class, null, 'pending'), $token)->assertForbidden();
        $this->scan($this->teacher, $token)->assertForbidden();
        $this->assertDatabaseCount('attendance_records', 0);

        // Guests are sent to log in, then back to the scan URL.
        auth()->logout();
        $this->get('/scan?t='.$token)->assertRedirect('/login');
        $student = $this->enroll($this->class);
        $this->withSession(['url.intended' => url('/scan?t='.$token)])
            ->post('/login', ['email' => $student->email, 'password' => 'password'])
            ->assertRedirect(url('/scan?t='.$token));
    }

    public function test_fr_att_07_scan_stores_device_and_ip_without_asking_for_location(): void
    {
        $s = $this->makeSession();
        $this->openTimeIn($s);
        $student = $this->enroll($this->class);

        $this->scan($student, $this->token($s), ['device_id' => '6f1c2b6e-1111-4a4a-9a9a-123456789abc'], '192.168.1.20')->assertOk();

        $this->assertDatabaseHas('scan_logs', [
            'stage' => 'time_in', 'ip' => '192.168.1.20', 'device_id' => '6f1c2b6e-1111-4a4a-9a9a-123456789abc',
        ]);

        $this->actingAs($student)->get('/scan?t='.$this->token($s))->assertOk()
            ->assertSee('localStorage', false)->assertDontSee('geolocation', false)->assertDontSee('Checking your location');
    }

    public function test_fr_att_08_repeat_scan_says_already_recorded_and_changes_nothing(): void
    {
        $s = $this->makeSession();
        $this->openTimeIn($s);
        $student = $this->enroll($this->class);

        $this->scan($student, $this->token($s))->assertJson(['ok' => true]);
        $this->travel(20)->minutes();
        $this->scan($student, $this->token($s))->assertJson(['title' => 'Already recorded at 8:00 AM']);

        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertDatabaseCount('scan_logs', 1);
        $this->assertSame('present', AttendanceRecord::first()->status);
    }

    public function test_fr_att_09_present_within_grace_late_after_and_time_out_keeps_status(): void
    {
        $s = $this->makeSession(['grace_minutes' => 15]);
        $this->openTimeIn($s);
        $onTime = $this->enroll($this->class);
        $late = $this->enroll($this->class);

        $this->travel(15)->minutes(); // exactly at the deadline
        $this->scan($onTime, $this->token($s))->assertJson(['title' => 'Time In recorded — Present']);
        $this->travel(1)->minutes();
        $this->scan($late, $this->token($s), [], '10.0.0.2')->assertJson(['title' => 'Time In recorded — Late']);

        $this->actingAs($this->teacher)->post("/sessions/{$s->id}/stages/time_in/close");
        $this->actingAs($this->teacher)->post("/sessions/{$s->id}/stages/time_out/open");
        $s->refresh();
        $this->scan($late, $this->token($s, 'time_out'))->assertJson(['ok' => true]);

        $record = AttendanceRecord::where('student_id', $late->id)->first();
        $this->assertSame('late', $record->status);
        $this->assertNotNull($record->time_out_at);
    }

    /* ---------- No flags ---------- */

    /** Suspicious-scan flags were removed: scans never create flags and the page has no Flagged scans card. */
    public function test_scans_create_no_flags_and_the_session_page_has_no_flag_section(): void
    {
        $s = $this->makeSession();
        $this->openTimeIn($s);
        $device = '6f1c2b6e-2222-4a4a-9a9a-123456789abc';

        // What used to be flagged (same device, many scans from one IP) is simply recorded.
        foreach (range(1, 3) as $i) {
            $this->scan($this->enroll($this->class), $this->token($s), ['device_id' => $device], '10.9.9.9')->assertJson(['ok' => true]);
        }
        $this->assertSame(3, AttendanceRecord::where('status', 'present')->count());
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('scan_flags'));

        $this->actingAs($this->teacher)->get("/sessions/{$s->id}")->assertOk()
            ->assertDontSee('Flagged scans')->assertDontSee('NO_LOCATION')->assertDontSee('⚑');
        $this->actingAs($this->teacher)->get("/sessions/{$s->id}/live")->assertOk()->assertDontSee('flagged');
        $this->actingAs($this->teacher)->getJson("/sessions/{$s->id}/live/state")->assertJsonMissingPath('flags');
    }

    /* ---------- Manual and absences ---------- */

    public function test_fr_att_12_manual_record_and_status_change(): void
    {
        $s = $this->makeSession();
        $student = $this->enroll($this->class);

        $this->actingAs($this->teacher)->post("/sessions/{$s->id}/records", ['student_id' => $student->id, 'status' => 'late'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('attendance_records', ['student_id' => $student->id, 'status' => 'late', 'source' => 'manual']);

        $this->actingAs($this->teacher)->post("/sessions/{$s->id}/records", ['student_id' => $student->id, 'status' => 'absent']);
        $this->assertSame('absent', AttendanceRecord::first()->status);
        Notification::assertSentTo($student, AbsenceAlert::class);

        $this->actingAs($this->teacher)->post("/sessions/{$s->id}/records", ['student_id' => $student->id, 'status' => 'excused'])->assertSessionHasErrors('status');
        $this->actingAs($this->teacher)->post("/sessions/{$s->id}/records", ['student_id' => User::factory()->create()->id, 'status' => 'present'])->assertStatus(422);
    }

    public function test_fr_att_13_closing_time_out_marks_missing_students_absent_and_alerts(): void
    {
        $s = $this->makeSession();
        $this->openTimeIn($s);
        $full = $this->enroll($this->class);
        $noOut = $this->enroll($this->class);
        $noShow = $this->enroll($this->class);
        $manual = $this->enroll($this->class);

        $this->scan($full, $this->token($s), [], '10.0.0.1');
        $this->scan($noOut, $this->token($s), [], '10.0.0.2');
        $this->actingAs($this->teacher)->post("/sessions/{$s->id}/records", ['student_id' => $manual->id, 'status' => 'present']);
        $this->actingAs($this->teacher)->post("/sessions/{$s->id}/stages/time_in/close");
        $this->actingAs($this->teacher)->post("/sessions/{$s->id}/stages/time_out/open");
        $s->refresh();
        $this->scan($full, $this->token($s, 'time_out'), [], '10.0.0.1');

        $this->actingAs($this->teacher)->post("/sessions/{$s->id}/stages/time_out/close")->assertSessionHasNoErrors();

        $status = fn (User $u) => AttendanceRecord::where('student_id', $u->id)->value('status');
        $this->assertSame('present', $status($full));
        $this->assertSame('absent', $status($noOut));
        $this->assertSame('absent', $status($noShow));
        $this->assertSame('present', $status($manual));

        Notification::assertSentTo($noOut, AbsenceAlert::class);
        Notification::assertSentTo($noShow, AbsenceAlert::class);
        Notification::assertNotSentTo($full, AbsenceAlert::class);

        // Teacher can still change it afterwards.
        $this->actingAs($this->teacher)->post("/sessions/{$s->id}/records", ['student_id' => $noOut->id, 'status' => 'late']);
        $this->assertSame('late', $status($noOut));
    }

    public function test_fr_att_14_student_history_is_own_only_filtered_and_has_no_flags(): void
    {
        $me = $this->enroll($this->class);
        $other = $this->enroll($this->class);
        $s = $this->makeSession(['title' => 'Week 1']);
        $this->openTimeIn($s);
        $this->scan($me, $this->token($s), [], '10.0.0.1');
        $this->scan($other, $this->token($s), [], '10.0.0.2');

        $otherClass = $this->makeClass(null, ['subject' => 'OTHERSUBJ', 'school_term' => '2025-2026']);
        $this->enroll($otherClass, $me);
        $s2 = AttendanceSession::factory()->for($otherClass, 'schoolClass')->create();
        AttendanceRecord::factory()->create(['attendance_session_id' => $s2->id, 'student_id' => $me->id, 'status' => 'late']);

        $rows = fn ($res) => substr_count($res->getContent(), '<tr>') - 1; // minus the header row

        $all = $this->actingAs($me)->get('/my/attendance')->assertOk();
        $all->assertSee('Week 1')->assertSee('Present')->assertSee('Late')->assertDontSee('NO_LOCATION')->assertDontSee('Flag');
        $this->assertSame(2, $rows($all));

        $byTerm = $this->actingAs($me)->get('/my/attendance?term=2025-2026');
        $this->assertSame(1, $rows($byTerm));
        $byTerm->assertSee('Late')->assertDontSee('Week 1');

        $byClass = $this->actingAs($me)->get('/my/attendance?class_id='.$this->class->id);
        $this->assertSame(1, $rows($byClass));
        $byClass->assertSee('Week 1')->assertDontSee('Late');
    }
}
