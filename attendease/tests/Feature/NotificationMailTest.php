<?php

namespace Tests\Feature;

use App\Models\AttendanceSession;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\AbsenceAlert;
use App\Notifications\ClassNotification;
use App\Notifications\EmailChangedNotice;
use App\Notifications\JoinRequestReceived;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\SecurityAlert;
use App\Notifications\WelcomeNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_fr_notif_01_bell_count_list_mark_one_and_mark_all(): void
    {
        $class = $this->makeClass();
        $student = $this->enroll($class);
        $session = AttendanceSession::factory()->for($class, 'schoolClass')->create();

        // Real (non-faked) notifications: the database copy is written synchronously.
        $student->notify(new AbsenceAlert($session));
        $student->notify(new AbsenceAlert($session));
        $student->notify(new AbsenceAlert($session));
        $this->assertSame(3, $student->unreadNotifications()->count());

        $this->actingAs($student)->get('/dashboard')->assertSee('3 unread');
        $this->actingAs($student)->get('/notifications')->assertOk()->assertSee('Marked absent');

        $first = $student->notifications()->first();
        $this->actingAs($student)->post("/notifications/{$first->id}/read", ['follow' => 1])
            ->assertRedirect(route('attendance.history', ['class_id' => $class->id]));
        $this->assertSame(2, $student->unreadNotifications()->count());

        // Someone else's notification can't be touched.
        $this->actingAs(User::factory()->create())->post("/notifications/{$student->notifications()->latest()->first()->id}/read")->assertNotFound();

        $this->actingAs($student)->post('/notifications/read-all');
        $this->assertSame(0, $student->unreadNotifications()->count());
    }

    public function test_fr_notif_02_class_notifications_use_database_and_mail(): void
    {
        $class = $this->makeClass();
        $student = $this->enroll($class, null, Enrollment::PENDING);
        $n = new JoinRequestReceived(Enrollment::first()->load('schoolClass', 'student'));

        $this->assertSame(['database', 'mail'], $n->via($class->teacher));
        $this->assertSame(['database' => 'sync'], $n->viaConnections());
        $this->assertInstanceOf(ShouldQueue::class, $n);
        $this->assertSame(route('classes.students', $class), $n->toArray($class->teacher)['url']);
        $this->assertTrue(is_subclass_of(AbsenceAlert::class, ClassNotification::class));
    }

    public function test_fr_mail_01_welcome_email_greets_by_name_and_links_dashboard(): void
    {
        $user = User::factory()->create(['name' => 'Juan Santos']);
        $html = (string) (new WelcomeNotification)->toMail($user)->render();

        $this->assertStringContainsString('Welcome, Juan Santos!', $html);
        $this->assertStringContainsString(route('dashboard'), $html);
    }

    public function test_fr_mail_02_security_alert_shows_time_ip_browser_and_reset_link(): void
    {
        $user = User::factory()->create();
        $alert = new SecurityAlert(SecurityAlert::NEW_SIGN_IN, ['time' => 'Nov 12, 2026 8:00 AM (Asia/Manila)', 'ip' => '203.0.113.9', 'browser' => 'Chrome on Android']);

        $this->assertSame(['mail'], $alert->via($user));
        $html = (string) $alert->toMail($user)->render();

        foreach (['Asia/Manila', '203.0.113.9', 'Chrome on Android', 'If this wasn', 't you, reset your password', route('password.request')] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    public function test_fr_mail_03_all_mail_is_queued_and_layout_shows_school_name(): void
    {
        foreach ([WelcomeNotification::class, SecurityAlert::class, EmailChangedNotice::class, ResetPasswordNotification::class, ClassNotification::class] as $class) {
            $this->assertContains(ShouldQueue::class, class_implements($class), $class);
        }

        $html = (string) (new EmailChangedNotice)->toMail(User::factory()->create())->render();
        $this->assertStringContainsString(config('app.school_name'), $html);
    }
}
