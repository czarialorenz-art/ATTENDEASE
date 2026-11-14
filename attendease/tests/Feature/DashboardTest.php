<?php

namespace Tests\Feature;

use App\Models\AttendanceSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** FR-CLS-08: dashboard order and the live "Today's sessions" card. */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_classes_i_teach_comes_before_todays_sessions(): void
    {
        $class = $this->makeClass(null, ['subject' => 'SYSADMIN']);
        AttendanceSession::factory()->for($class, 'schoolClass')->create(['title' => 'Week 3']);

        $this->actingAs($class->teacher)->get('/dashboard')->assertOk()
            ->assertSeeInOrder(['Classes I teach', 'SYSADMIN', "Today's sessions", 'Week 3'], false);
    }

    public function test_todays_sessions_shows_unfinished_ones_ongoing_first_and_hides_completed(): void
    {
        $class = $this->makeClass();
        AttendanceSession::factory()->for($class, 'schoolClass')->create(['title' => 'Not Started Yet', 'created_at' => now()->subHours(3)]);
        AttendanceSession::factory()->for($class, 'schoolClass')->timeInOpen()->create(['title' => 'Running Now', 'created_at' => now()->subHour()]);
        AttendanceSession::factory()->for($class, 'schoolClass')->completed()->create(['title' => 'All Done']);
        AttendanceSession::factory()->for($class, 'schoolClass')->create(['title' => 'Yesterday', 'date' => now()->subDay()->toDateString()]);

        $titles = $this->actingAs($class->teacher)->get('/dashboard')->viewData('todaySessions')->pluck('title')->all();

        $this->assertSame(['Running Now', 'Not Started Yet'], $titles); // ongoing first; completed and other days gone
        $this->actingAs($class->teacher)->get('/dashboard')
            ->assertSee('Time In open')->assertSee('Show QR')->assertDontSee('All Done');
    }

    public function test_refresh_picks_up_new_sessions_and_drops_completed_ones(): void
    {
        $class = $this->makeClass();
        $this->actingAs($class->teacher)->get('/dashboard')
            ->assertSee('data-refresh-url="'.route('dashboard.today').'"', false)
            ->assertSee('No sessions are running today');

        // A session is created and opened elsewhere: the next refresh shows it.
        $s = AttendanceSession::factory()->for($class, 'schoolClass')->timeInOpen()->create(['title' => 'Fresh Session']);
        $this->actingAs($class->teacher)->get('/dashboard/today')->assertOk()
            ->assertSee('Fresh Session')->assertSee('Time In open')
            ->assertDontSee('<html', false); // just the card, not a whole page

        // Once Time Out closes it's completed, and the next refresh drops it.
        $s->update(['time_in_closed_at' => now(), 'time_out_opened_at' => now(), 'time_out_closed_at' => now()]);
        $this->actingAs($class->teacher)->get('/dashboard/today')
            ->assertDontSee('Fresh Session')->assertSee('No sessions are running today');
    }

    public function test_students_see_todays_sessions_for_their_classes_with_their_own_status(): void
    {
        $this->freezeManila('2026-11-14 08:00:00');
        $class = $this->makeClass(null, ['subject' => 'SYSADMIN']);
        $student = $this->enroll($class);
        $classmate = $this->enroll($class);
        $other = $this->makeClass(null, ['subject' => 'NOTMINE']);

        $open = AttendanceSession::factory()->for($class, 'schoolClass')->timeInOpen()->create(['title' => 'Week 3']);
        AttendanceSession::factory()->for($class, 'schoolClass')->create(['title' => 'Later Today']);
        AttendanceSession::factory()->for($class, 'schoolClass')->completed()->create(['title' => 'Finished']);
        AttendanceSession::factory()->for($other, 'schoolClass')->timeInOpen()->create(['title' => 'Other Class']);

        $page = $this->actingAs($student)->get('/dashboard')->assertOk();
        $this->assertSame(['Week 3', 'Later Today'], $page->viewData('myTodaySessions')->pluck('title')->all());
        $page->assertSeeInOrder(['Scan attendance QR', "Classes I'm in", 'id="my-today-sessions"', 'Week 3', 'Later Today'], false)
            ->assertSee('Scan Time In')->assertDontSee('Finished')->assertDontSee('Other Class')
            ->assertSee('data-refresh-url="'.route('dashboard.today.student').'"', false);

        // After scanning, the card shows their own status, not a classmate's.
        \App\Models\AttendanceRecord::factory()->create(['attendance_session_id' => $open->id, 'student_id' => $student->id, 'status' => 'present', 'time_in_at' => now()]);
        \App\Models\AttendanceRecord::factory()->create(['attendance_session_id' => $open->id, 'student_id' => $classmate->id, 'status' => 'late', 'time_in_at' => now()]);

        $this->actingAs($student)->get('/dashboard/today/mine')->assertOk()
            ->assertSee('✓ Scanned')->assertSee('Present')->assertSee('Time In 8:00 AM')
            ->assertDontSee('Scan Time In')->assertDontSee('badge-late', false) // the classmate's Late isn't shown
            ->assertDontSee('<html', false);
    }

    public function test_student_card_drops_completed_sessions_and_hides_for_non_students(): void
    {
        $class = $this->makeClass();
        $student = $this->enroll($class);
        $s = AttendanceSession::factory()->for($class, 'schoolClass')->timeInOpen()->create(['title' => 'Closing Soon']);

        $this->actingAs($student)->get('/dashboard/today/mine')->assertSee('Closing Soon');

        $s->update(['time_in_closed_at' => now(), 'time_out_opened_at' => now(), 'time_out_closed_at' => now()]);
        $this->actingAs($student)->get('/dashboard/today/mine')
            ->assertDontSee('Closing Soon')->assertSee('No sessions in your classes today');

        // Pending students and the teacher don't get the student card.
        $pending = $this->enroll($class, null, 'pending');
        $this->actingAs($pending)->get('/dashboard')->assertDontSee('id="my-today-sessions"', false);
        $this->actingAs($class->teacher)->get('/dashboard')->assertDontSee('id="my-today-sessions"', false);
    }

    public function test_only_the_teachers_own_classes_and_only_for_teachers(): void
    {
        $mine = $this->makeClass();
        $theirs = $this->makeClass();
        AttendanceSession::factory()->for($theirs, 'schoolClass')->create(['title' => 'Someone Elses']);

        $this->actingAs($mine->teacher)->get('/dashboard/today')->assertDontSee('Someone Elses');

        // A student who teaches nothing sees no "Today's sessions" card.
        $student = $this->enroll($theirs);
        $this->actingAs($student)->get('/dashboard')->assertDontSee('id="today-sessions"', false);

        auth()->logout();
        $this->get('/dashboard/today')->assertRedirect('/login');
    }
}
