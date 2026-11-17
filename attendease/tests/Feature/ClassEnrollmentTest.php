<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\User;
use App\Notifications\JoinRequestDecided;
use App\Notifications\JoinRequestReceived;
use App\Notifications\RemovedFromClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ClassEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private function classData(array $overrides = []): array
    {
        return array_merge([
            'subject' => 'ITE101', 'year_level' => '1st Year', 'section' => 'BSIT-1A',
            'school_term' => '2026-2027', 'semester' => '1st',
            'semester_start' => '2026-08-01', 'semester_end' => '2026-12-15',
        ], $overrides);
    }

    public function test_fr_cls_01_teacher_creates_class_and_becomes_its_teacher(): void
    {
        $teacher = User::factory()->teacher()->create();

        $this->actingAs($teacher)->post('/classes', $this->classData())->assertRedirect();

        $class = SchoolClass::first();
        $this->assertSame($teacher->id, $class->teacher_id);
        $this->assertSame('ITE101', $class->subject);
    }

    /** FR-ACL-03: only Teachers create classes. */
    public function test_students_and_admins_cannot_create_classes(): void
    {
        foreach ([User::factory()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->get('/classes/create')->assertForbidden();
            $this->actingAs($user)->post('/classes', $this->classData())->assertForbidden();
        }
        $this->assertDatabaseCount('classes', 0);
    }

    public function test_fr_cls_01_validation(): void
    {
        $teacher = User::factory()->teacher()->create();

        $this->actingAs($teacher)->post('/classes', $this->classData(['semester_end' => '2026-07-01']))->assertSessionHasErrors('semester_end');
        $this->actingAs($teacher)->post('/classes', $this->classData(['semester' => '3rd']))->assertSessionHasErrors('semester');
        $this->actingAs($teacher)->post('/classes', $this->classData(['subject' => '']))->assertSessionHasErrors('subject');
        $this->assertDatabaseCount('classes', 0);
    }

    public function test_fr_cls_02_code_is_unique_6_to_8_chars_without_lookalikes(): void
    {
        $codes = collect(range(1, 40))->map(fn () => $this->makeClass()->code);

        $this->assertSame($codes->count(), $codes->unique()->count());
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{6,8}$/', $code);
        }

        $class = $this->makeClass();
        $this->actingAs($class->teacher)->get("/classes/{$class->id}")->assertSee($class->code)->assertSee('data-copy', false);
    }

    public function test_fr_cls_03_edit_class_schedule_and_location(): void
    {
        $class = $this->makeClass();

        $this->actingAs($class->teacher)->put("/classes/{$class->id}", $this->classData(['section' => 'B']))->assertSessionHasNoErrors();
        $this->assertSame('B', $class->fresh()->section);

        $class->update(['latitude' => 14.65, 'longitude' => 121.04]); // from before the map fields were removed

        $this->actingAs($class->teacher)->put("/classes/{$class->id}/schedule", [
            'schedules' => [
                ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '09:30'],
                ['day_of_week' => 3, 'start_time' => '13:00', 'end_time' => '14:30', 'room' => 'ignored'], // rows no longer take a room
                ['day_of_week' => '', 'start_time' => '', 'end_time' => ''], // blank row ignored
            ],
            'building' => ' Main Building ', 'floor' => '3rd', 'room' => 'Tesda Lab',
            'latitude' => '1', 'longitude' => '2', 'radius_m' => '50', // no longer accepted
        ])->assertSessionHasNoErrors();

        $class->refresh();
        $this->assertCount(2, $class->schedules);
        $this->assertNull($class->schedules->first()->room);
        $this->assertSame(['Main Building', '3rd', 'Tesda Lab'], [$class->building, $class->floor, $class->room]);
        $this->assertSame('Tesda Lab · 3rd floor · Main Building', $class->locationLabel());
        $this->assertNull($class->latitude); // the distance check is off now
        $this->assertFalse($class->hasLocation());

        // The form has Building / Floor / Room and no map fields or per-row room.
        $this->actingAs($class->teacher)->get("/classes/{$class->id}/edit")->assertOk()
            ->assertSee('name="building"', false)->assertSee('name="floor"', false)->assertSee('name="room"', false)
            ->assertDontSee('name="latitude"', false)->assertDontSee('Radius (m)')->assertDontSee('Use my current location')
            ->assertDontSee('[room]', false);

        // Shown on the class pages.
        $this->actingAs($class->teacher)->get("/classes/{$class->id}")->assertSee('Classroom: Tesda Lab · 3rd floor · Main Building');
        $student = $this->enroll($class);
        $this->actingAs($student)->get("/classes/{$class->id}")->assertSee('Tesda Lab · 3rd floor · Main Building');

        $this->actingAs($class->teacher)->put("/classes/{$class->id}/schedule", [
            'schedules' => [['day_of_week' => 1, 'start_time' => '10:00', 'end_time' => '09:00']],
        ])->assertSessionHasErrors('schedules.0.end_time');
    }

    public function test_fr_cls_04_join_by_code_creates_pending_request(): void
    {
        Notification::fake();
        $class = $this->makeClass();
        $student = User::factory()->create();

        $this->actingAs($student)->post('/classes/join', ['code' => strtolower($class->code)])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('enrollments', ['class_id' => $class->id, 'student_id' => $student->id, 'status' => 'pending']);
    }

    public function test_fr_cls_04_wrong_code_duplicate_already_in_and_own_class_are_rejected(): void
    {
        Notification::fake();
        $class = $this->makeClass();
        $student = User::factory()->create();

        $this->actingAs($student)->post('/classes/join', ['code' => 'ZZZZZZZ'])->assertSessionHasErrors('code');

        $this->actingAs($student)->post('/classes/join', ['code' => $class->code]);
        $this->actingAs($student)->post('/classes/join', ['code' => $class->code])->assertSessionHasErrors('code');

        Enrollment::where('student_id', $student->id)->update(['status' => 'approved']);
        $this->actingAs($student)->post('/classes/join', ['code' => $class->code])->assertSessionHasErrors('code');

        // FR-ACL-03: a Teacher can't join classes at all.
        $this->actingAs($class->teacher)->post('/classes/join', ['code' => $class->code])->assertForbidden();
        $this->assertDatabaseCount('enrollments', 1);
    }

    public function test_fr_cls_05_teacher_approves_or_rejects_and_rejected_may_ask_again(): void
    {
        Notification::fake();
        $class = $this->makeClass();
        $a = $this->enroll($class, null, Enrollment::PENDING);
        $b = $this->enroll($class, null, Enrollment::PENDING);
        $ea = Enrollment::where('student_id', $a->id)->first();
        $eb = Enrollment::where('student_id', $b->id)->first();

        $this->actingAs($class->teacher)->get("/classes/{$class->id}/students")->assertSee($a->listName())->assertSee($b->listName());
        $this->actingAs($class->teacher)->post("/enrollments/{$ea->id}/approve");
        $this->actingAs($class->teacher)->post("/enrollments/{$eb->id}/reject");

        $this->assertSame('approved', $ea->fresh()->status);
        $this->assertNotNull($ea->fresh()->decided_at);
        $this->assertSame('rejected', $eb->fresh()->status);

        $this->actingAs($b)->post('/classes/join', ['code' => $class->code])->assertSessionHasNoErrors();
        $this->assertSame('pending', $eb->fresh()->status);
    }

    public function test_students_page_shows_each_students_section(): void
    {
        $class = $this->makeClass();
        $this->enroll($class, User::factory()->create(['name' => 'Ana Cruz', 'section' => 'BSIT-2A']));
        $this->enroll($class, User::factory()->create(['name' => 'Ben Lim', 'section' => null]));
        $this->enroll($class, User::factory()->create(['name' => 'Cara Uy', 'section' => 'BSIT-2C']), Enrollment::PENDING);

        $this->actingAs($class->teacher)->get("/classes/{$class->id}/students")->assertOk()
            ->assertSeeInOrder(['Cruz, Ana', 'Section BSIT-2A'])
            ->assertSeeInOrder(['Lim, Ben', 'No section'])
            ->assertSeeInOrder(['Uy, Cara', 'Section BSIT-2C']); // join requests show it too
    }

    public function test_fr_cls_10_students_page_filters_by_section_and_searches_by_name(): void
    {
        $class = $this->makeClass();
        $this->enroll($class, User::factory()->create(['name' => 'Ana Cruz', 'section' => 'A', 'id_number' => '2023-00011']));
        $this->enroll($class, User::factory()->create(['name' => 'Ben Lim', 'section' => 'B']));
        $this->enroll($class, User::factory()->create(['name' => 'Bea Santos', 'section' => 'b'])); // same section, other case
        $this->enroll($class, User::factory()->create(['name' => 'Carl Reyes', 'section' => null]));
        $this->enroll($class, User::factory()->create(['name' => 'Dina Ong', 'section' => 'A']), Enrollment::PENDING);

        $names = fn (string $query, string $list = 'approved') => $this->actingAs($class->teacher)
            ->get("/classes/{$class->id}/students?{$query}")->assertOk()
            ->viewData($list)->map(fn ($e) => $e->student->name)->values()->all();

        // Dropdown lists the sections that exist (case-insensitive), plus "No section".
        $page = $this->actingAs($class->teacher)->get("/classes/{$class->id}/students")->assertOk();
        $this->assertSame(['A', 'B'], $page->viewData('sections')->all());
        $page->assertSee('All sections')->assertSee('No section')->assertSee('placeholder="Student name, number or email"', false);

        $this->assertSame(['Ana Cruz'], $names('section=A'));
        $this->assertSame(['Dina Ong'], $names('section=A', 'pending'));            // applies to join requests too
        $this->assertSame(['Ben Lim', 'Bea Santos'], $names('section=b'));
        $this->assertSame(['Carl Reyes'], $names('section=__none'));
        $this->assertSame(['Bea Santos'], $names('q=SANTOS'));                      // name, any case
        $this->assertSame(['Ben Lim'], $names('q=n%20li'));                         // part of a name
        $this->assertSame(['Ana Cruz'], $names('q=00011'));                         // student number
        $this->assertSame(['Ben Lim'], $names('q=ben&section=B'));                  // both together
        $this->assertSame([], $names('q=zzz'));

        $this->actingAs($class->teacher)->get("/classes/{$class->id}/students?section=B")
            ->assertSee('2 of 4')->assertSee('Clear');
        $this->actingAs($class->teacher)->get("/classes/{$class->id}/students?q=zzz")
            ->assertSee('No students match your search.');
    }

    public function test_fr_cls_06_removed_student_loses_access_but_records_are_kept(): void
    {
        Notification::fake();
        $class = $this->makeClass();
        $student = $this->enroll($class);
        $session = AttendanceSession::factory()->for($class, 'schoolClass')->create();
        AttendanceRecord::factory()->create(['attendance_session_id' => $session->id, 'student_id' => $student->id]);
        $enrollment = Enrollment::where('student_id', $student->id)->first();

        $this->actingAs($class->teacher)->post("/enrollments/{$enrollment->id}/remove");

        $this->assertSame('removed', $enrollment->fresh()->status);
        $this->actingAs($student)->get("/classes/{$class->id}")->assertForbidden();
        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_fr_cls_07_each_step_notifies_the_other_side(): void
    {
        Notification::fake();
        $class = $this->makeClass();
        $student = User::factory()->create();

        $this->actingAs($student)->post('/classes/join', ['code' => $class->code]);
        Notification::assertSentTo($class->teacher, JoinRequestReceived::class);

        $e = Enrollment::first();
        $this->actingAs($class->teacher)->post("/enrollments/{$e->id}/approve");
        Notification::assertSentTo($student, JoinRequestDecided::class, fn ($n) => $n->enrollment->status === 'approved');

        $this->actingAs($class->teacher)->post("/enrollments/{$e->id}/remove");
        Notification::assertSentTo($student, RemovedFromClass::class);

        $this->actingAs($student)->post('/classes/join', ['code' => $class->code]);
        $this->actingAs($class->teacher)->post("/enrollments/{$e->id}/reject");
        Notification::assertSentTo($student, JoinRequestDecided::class, fn ($n) => $n->enrollment->status === 'rejected');
    }

    public function test_fr_cls_08_dashboard_lists_taught_or_joined_classes_by_role(): void
    {
        $mine = $this->makeClass(null, ['subject' => 'TAUGHT1']);
        $this->enroll($mine, null, Enrollment::PENDING);
        $this->enroll($mine, null, Enrollment::PENDING);
        $me = User::factory()->create();
        $this->enroll($this->makeClass(null, ['subject' => 'JOINED1']), $me);
        $this->enroll($this->makeClass(null, ['subject' => 'WAITING1']), $me, Enrollment::PENDING);
        $this->makeClass(null, ['subject' => 'OTHER1']);

        $this->actingAs($mine->teacher)->get('/dashboard')->assertOk()
            ->assertSee('Classes I teach')->assertSee('TAUGHT1')->assertSee('2 pending')->assertSee('+ Create a class')
            ->assertDontSee("Classes I'm in", false)->assertDontSee('name="code"', false)->assertDontSee('OTHER1');

        $this->actingAs($me)->get('/dashboard')->assertOk()
            ->assertSee("Classes I'm in", false)->assertSee('JOINED1')->assertSee('WAITING1')->assertSee('Waiting for approval')
            ->assertSee('name="code"', false)
            ->assertDontSee('Classes I teach')->assertDontSee('+ Create a class')->assertDontSee('OTHER1');
    }
}
