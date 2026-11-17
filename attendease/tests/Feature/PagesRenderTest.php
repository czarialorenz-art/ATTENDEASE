<?php

namespace Tests\Feature;

use App\Models\AttendanceSession;
use App\Models\ClassSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Smoke test (section 10): every page renders for the right user. */
class PagesRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_render(): void
    {
        foreach (['/register', '/login', '/forgot-password', '/reset-password/abc?email=a@mqcqc.ph.education'] as $url) {
            $this->get($url)->assertOk()->assertSee(config('app.school_name'));
        }
    }

    public function test_teacher_and_student_pages_render(): void
    {
        $class = $this->makeClass();
        ClassSchedule::factory()->create(['class_id' => $class->id, 'start_time' => '07:45']);
        $class->update(['room' => 'R-301', 'building' => 'Main Building']);
        $student = $this->enroll($class);
        $session = AttendanceSession::factory()->for($class, 'schoolClass')->create(['title' => 'Week 9']);

        $teacherPages = [
            '/dashboard' => 'Week 9', // today's sessions
            '/profile' => 'Profile',
            '/password' => 'Change password',
            '/notifications' => 'Notifications',
            '/classes/create' => 'Create a class',
            "/classes/{$class->id}" => $class->code,
            "/classes/{$class->id}/edit" => 'R-301',
            "/classes/{$class->id}/students" => $student->name,
            "/classes/{$class->id}/sessions" => 'Week 9',
            "/sessions/{$session->id}" => 'Open Time In',
            "/sessions/{$session->id}/live" => 'No stage is open',
            "/classes/{$class->id}/report" => 'Semester Attendance Summary',
        ];
        foreach ($teacherPages as $url => $text) {
            $this->actingAs($class->teacher)->get($url)->assertOk()->assertSee($text);
        }

        $studentPages = [
            '/dashboard' => 'Scan attendance QR',
            "/classes/{$class->id}" => 'My attendance this semester',
            '/scanner' => 'Scan attendance QR',
            '/my/attendance' => 'My attendance',
        ];
        foreach ($studentPages as $url => $text) {
            $this->actingAs($student)->get($url)->assertOk()->assertSee($text);
        }
    }
}
