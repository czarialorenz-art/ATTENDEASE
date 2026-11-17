<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\DashboardController as D;
use App\Models\Announcement;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\ClassSchedule;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** Admin side (FR-ADM, FR-LOG): Dashboard, User Management, Activity Log. */
class AdminTest extends TestCase
{
    use RefreshDatabase;

    private const DEFAULT_PASSWORD = 'Default@2026';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.import_default_password' => self::DEFAULT_PASSWORD]);
    }

    private function admin(array $attrs = []): User
    {
        return User::factory()->admin()->create($attrs);
    }

    private function email(string $local): string
    {
        return $local.'@'.config('app.allowed_email_domain');
    }

    /* ---------------- Access ---------------- */

    public function test_only_admins_can_open_the_admin_pages(): void
    {
        $pages = ['/admin', '/admin/users', '/admin/users/create', '/admin/users/import', '/admin/activity', '/admin/users/import/template/student.csv'];

        foreach ([User::factory()->create(), User::factory()->teacher()->create()] as $user) {
            foreach ($pages as $page) {
                $this->actingAs($user)->get($page)->assertForbidden();
            }
            $this->actingAs($user)->post('/admin/users', [])->assertForbidden();
        }

        auth()->logout();
        $this->get('/admin')->assertRedirect('/login');

        $admin = $this->admin();
        foreach ($pages as $page) {
            $this->actingAs($admin)->get($page)->assertOk();
        }
        $this->actingAs($admin)->get('/admin')
            ->assertSee('href="'.route('admin.dashboard').'"', false)
            ->assertSee('href="'.route('admin.users.index').'"', false)
            ->assertSee('href="'.route('admin.activity.index').'"', false)
            ->assertSee('User Management')->assertSee('Activity Log');
    }

    public function test_admin_logs_in_to_the_admin_dashboard(): void
    {
        $admin = $this->admin(['email' => $this->email('boss'), 'password' => 'Secret@123']);

        $this->post('/login', ['email' => $admin->email, 'password' => 'Secret@123'])->assertRedirect(route('admin.dashboard'));
    }

    /* ---------------- Dashboard ---------------- */

    public function test_dashboard_totals_count_active_accounts_and_distinct_subjects_and_sections(): void
    {
        User::factory()->count(3)->create();
        User::factory()->deactivated()->create(); // not counted
        $this->makeClass(null, ['subject' => 'ITE101', 'section' => 'A']);
        $this->makeClass(null, ['subject' => 'ite101 ', 'section' => 'B']); // same subject
        $this->makeClass(null, ['subject' => 'CS201', 'section' => 'a']);  // same section as A

        $res = $this->actingAs($this->admin())->get('/admin')->assertOk();

        $this->assertSame(['students' => 3, 'teachers' => 3, 'subjects' => 2, 'sections' => 2], $res->viewData('totals'));
    }

    public function test_dashboard_lists_the_days_classes_with_scanned_in_and_status(): void
    {
        $this->freezeManila('2026-11-12 10:00:00'); // a Thursday
        $thu = 4;

        $make = function (string $subject, string $start, string $end) use ($thu) {
            $class = $this->makeClass(null, ['subject' => $subject, 'room' => 'Lab 1', 'building' => 'Main']);
            ClassSchedule::factory()->create(['class_id' => $class->id, 'day_of_week' => $thu, 'start_time' => $start, 'end_time' => $end]);

            return $class;
        };

        $recorded = $make('RECORDED1', '08:00', '09:30');
        $students = collect(range(1, 3))->map(fn () => $this->enroll($recorded));
        $session = AttendanceSession::factory()->for($recorded, 'schoolClass')->create(['date' => '2026-11-12']);
        AttendanceRecord::factory()->create(['attendance_session_id' => $session->id, 'student_id' => $students[0]->id]);
        AttendanceRecord::factory()->create(['attendance_session_id' => $session->id, 'student_id' => $students[1]->id]);
        AttendanceRecord::factory()->create(['attendance_session_id' => $session->id, 'student_id' => $students[2]->id,
            'status' => AttendanceRecord::ABSENT, 'time_in_at' => null, 'source' => AttendanceRecord::SOURCE_MANUAL]);

        $missing = $make('MISSING1', '09:00', '10:30');
        $this->enroll($missing);
        $upcoming = $make('UPCOMING1', '13:00', '14:30');
        $cancelled = $make('CANCELLED1', '07:00', '08:00');
        Announcement::factory()->create(['class_id' => $cancelled->id, 'author_id' => $cancelled->teacher_id,
            'type' => Announcement::CANCELLATION, 'cancelled_date' => '2026-11-12']);

        // Not listed: another weekday, and a class whose semester is over.
        $other = $this->makeClass(null, ['subject' => 'FRIDAY1']);
        ClassSchedule::factory()->create(['class_id' => $other->id, 'day_of_week' => 5]);
        $old = $this->makeClass(null, ['subject' => 'OLDSEM1', 'semester_start' => '2026-01-05', 'semester_end' => '2026-05-30']);
        ClassSchedule::factory()->create(['class_id' => $old->id, 'day_of_week' => $thu]);

        $res = $this->actingAs($this->admin())->get('/admin')->assertOk();
        $rows = $res->viewData('rows')->keyBy('subject');

        $this->assertSame(['CANCELLED1', 'RECORDED1', 'MISSING1', 'UPCOMING1'], $res->viewData('rows')->pluck('subject')->all()); // by start time
        $this->assertSame([2, 3, D::RECORDED], [$rows['RECORDED1']['scanned_in'], $rows['RECORDED1']['enrolled'], $rows['RECORDED1']['status']]);
        $this->assertSame([0, 1, D::NO_ATTENDANCE], [$rows['MISSING1']['scanned_in'], $rows['MISSING1']['enrolled'], $rows['MISSING1']['status']]);
        $this->assertSame(D::UPCOMING, $rows['UPCOMING1']['status']);
        $this->assertSame(D::CANCELLED, $rows['CANCELLED1']['status']);
        $this->assertSame('Lab 1 · Main', $rows['RECORDED1']['room']);
        $this->assertSame('8:00 AM – 9:30 AM', $rows['RECORDED1']['time']);

        $res->assertSee('2 / 3')->assertSee('0 / 1')->assertDontSee('FRIDAY1')->assertDontSee('OLDSEM1');
        $this->assertSame(['MISSING1'], $res->viewData('missing')->pluck('subject')->all());
        $res->assertSeeInOrder(['data-missing-alert', 'MISSING1', $missing->teacher->name, '<table'], false);

        // Another date: Friday shows only the Friday class; an archived session doesn't count as recorded.
        $session->delete(); // archive
        $this->actingAs($this->admin())->get('/admin?date=2026-11-13')->assertSee('FRIDAY1')->assertDontSee('RECORDED1');
        $rows = $this->actingAs($this->admin())->get('/admin?date=2026-11-12')->viewData('rows')->keyBy('subject');
        $this->assertSame(D::NO_ATTENDANCE, $rows['RECORDED1']['status']);

        $this->actingAs($this->admin())->get('/admin?date=nope')->assertSessionHasErrors('date');
    }

    public function test_dashboard_uses_a_fixed_number_of_queries(): void
    {
        $this->freezeManila('2026-11-12 10:00:00');
        foreach (range(1, 6) as $i) {
            $class = $this->makeClass();
            ClassSchedule::factory()->create(['class_id' => $class->id, 'day_of_week' => 4]);
            $this->enroll($class);
        }
        $admin = $this->admin();
        $this->actingAs($admin);

        \DB::enableQueryLog();
        $this->get('/admin')->assertOk();
        $many = count(\DB::getQueryLog());

        foreach (range(1, 6) as $i) {
            $class = $this->makeClass();
            ClassSchedule::factory()->create(['class_id' => $class->id, 'day_of_week' => 4]);
        }
        \DB::flushQueryLog();
        $this->get('/admin')->assertOk();

        $this->assertSame($many, count(\DB::getQueryLog())); // no query per row
    }

    /* ---------------- User Management ---------------- */

    public function test_users_list_searches_filters_and_paginates(): void
    {
        $admin = $this->admin(['name' => 'Ada Admin']);
        User::factory()->create(['name' => 'Ana Cruz', 'id_number' => '2023-00011']);
        User::factory()->teacher()->create(['name' => 'Ben Lim']);
        User::factory()->deactivated()->create(['name' => 'Cara Uy']);

        $names = fn (string $q) => $this->actingAs($admin)->get('/admin/users?'.$q)->assertOk()
            ->viewData('users')->pluck('name')->all();

        $this->assertSame(['Ana Cruz'], $names('q=cruz'));
        $this->assertSame(['Ana Cruz'], $names('q=00011'));
        $this->assertSame(['Ben Lim'], $names('role=teacher'));
        $this->assertSame(['Ada Admin'], $names('role=admin'));
        $this->assertSame(['Cara Uy'], $names('status=deactivated'));
        $this->assertSame(['Ada Admin', 'Ana Cruz', 'Ben Lim'], $names('status=active'));
        $this->assertSame([], $names('q=cruz&role=teacher'));

        User::factory()->count(25)->create();
        $page = $this->actingAs($admin)->get('/admin/users?role=student')->viewData('users');
        $this->assertSame(20, $page->count());
        $this->assertSame(27, $page->total());
        $this->actingAs($admin)->get('/admin/users?role=student&page=2')->assertSee('role=student', false);
    }

    public function test_admin_adds_a_user_with_validation(): void
    {
        $admin = $this->admin();
        User::factory()->create(['id_number' => '2023-00001', 'email' => $this->email('taken')]);

        $this->actingAs($admin)->post('/admin/users', ['name' => '', 'id_number' => '2023-00001', 'email' => $this->email('taken'), 'role' => 'boss'])
            ->assertSessionHasErrors(['name', 'id_number', 'email', 'role']);
        $this->actingAs($admin)->post('/admin/users', ['name' => 'X', 'id_number' => '9', 'email' => 'x@gmail.com', 'role' => 'student'])
            ->assertSessionHasErrors('email');
        $this->actingAs($admin)->post('/admin/users', ['name' => 'X', 'id_number' => '9', 'email' => $this->email('x'), 'role' => 'student', 'password' => 'weak'])
            ->assertSessionHasErrors('password');

        $this->actingAs($admin)->post('/admin/users', [
            'name' => 'Juan Dela Cruz', 'id_number' => 'T-100', 'email' => ' JUAN@'.config('app.allowed_email_domain'), 'role' => 'teacher', 'department' => 'BSIT',
        ])->assertRedirect(route('admin.users.index'));

        $juan = User::where('id_number', 'T-100')->first();
        $this->assertSame([User::TEACHER, $this->email('juan'), 'BSIT'], [$juan->role, $juan->email, $juan->department]);
        $this->assertTrue(Hash::check(self::DEFAULT_PASSWORD, $juan->password)); // blank password = default

        $log = Activity::where('event', Audit::USER_ADDED)->sole();
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertStringContainsString('Added Teacher Juan Dela Cruz (T-100)', $log->description);

        // Without a default password, one must be typed.
        config(['app.import_default_password' => null]);
        $this->actingAs($admin)->post('/admin/users', ['name' => 'Y', 'id_number' => 'Y-1', 'email' => $this->email('y'), 'role' => 'student'])
            ->assertSessionHasErrors('password');
    }

    public function test_admin_edits_a_user_and_role_changes_are_logged(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['name' => 'Ana Cruz', 'id_number' => 'S-1', 'email' => $this->email('ana'), 'course' => 'BSIT', 'year_level' => 3, 'section' => 'A']);
        $fields = ['name' => 'Ana Cruz', 'id_number' => 'S-1', 'email' => $this->email('ana')];

        $this->actingAs($admin)->get("/admin/users/{$user->id}/edit")->assertOk()->assertSee('Ana Cruz')
            ->assertSee('name="course"', false)->assertSee('name="year_level"', false)->assertSee('name="section"', false);

        // A student needs course, year level and section from the lists.
        $this->actingAs($admin)->put("/admin/users/{$user->id}", [...$fields, 'role' => 'student'])->assertSessionHasErrors(['course', 'year_level', 'section']);
        $this->actingAs($admin)->put("/admin/users/{$user->id}", [...$fields, 'role' => 'student', 'course' => 'BSCS', 'year_level' => 5, 'section' => 'E'])
            ->assertSessionHasErrors(['course', 'year_level', 'section']);

        $this->actingAs($admin)->put("/admin/users/{$user->id}", [
            ...$fields, 'role' => 'student', 'course' => 'BSIT', 'year_level' => 4, 'section' => 'b', 'password' => 'NewPass@123',
        ])->assertRedirect(route('admin.users.index'));

        $user->refresh();
        $this->assertSame(['BSIT', 4, 'B'], [$user->course, $user->year_level, $user->section]);
        $this->assertSame('BSIT · 4th Year · Section B', $user->academicLabel());
        $this->assertTrue(Hash::check('NewPass@123', $user->password));
        $this->assertSame('Ana Cruz (S-1): Year level: 3 → 4; Section: A → B; password reset', Activity::where('event', Audit::USER_EDITED)->sole()->description);

        // Made a Teacher: course, year and section are cleared.
        $this->actingAs($admin)->put("/admin/users/{$user->id}", [...$fields, 'role' => 'teacher', 'section' => 'B'])->assertSessionHasNoErrors();
        $user->refresh();
        $this->assertSame([User::TEACHER, null, null, null], [$user->role, $user->course, $user->year_level, $user->section]);
        $this->assertSame('Ana Cruz (S-1): Student → Teacher', Activity::where('event', Audit::ROLE_CHANGED)->sole()->description);

        // Unique checks ignore the user's own values; another user's are rejected.
        User::factory()->create(['id_number' => 'S-2']);
        $this->actingAs($admin)->put("/admin/users/{$user->id}", ['name' => 'Ana Cruz', 'id_number' => 'S-2', 'email' => $this->email('ana'), 'role' => 'teacher'])
            ->assertSessionHasErrors('id_number');

        // Saving with nothing changed logs nothing.
        $before = Activity::count();
        $this->actingAs($admin)->put("/admin/users/{$user->id}", [...$fields, 'role' => 'teacher']);
        $this->assertSame($before, Activity::count());
    }

    /** FR-ADM-19: department is a dropdown from the fixed list; an old typed value can be kept. */
    public function test_department_is_a_dropdown_from_the_list(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/users/create')->assertOk()
            ->assertSee('<select id="f_department" name="department"', false)->assertSee('<option value="BSIT"', false);

        $base = ['name' => 'Ben Lim', 'id_number' => 'T-1', 'role' => 'teacher'];
        $this->actingAs($admin)->post('/admin/users', [...$base, 'department' => 'Nursing'])->assertSessionHasErrors('department');
        $this->actingAs($admin)->post('/admin/users', [...$base, 'department' => 'BSIT'])->assertSessionHasNoErrors();
        $this->assertSame('BSIT', User::where('id_number', 'T-1')->value('department'));

        // A department typed before the list existed is shown and can be kept, but not chosen for others.
        $old = User::factory()->teacher()->create(['name' => 'Cara Uy', 'department' => 'CCS']);
        $this->actingAs($admin)->get("/admin/users/{$old->id}/edit")->assertSee('CCS (old value, not in the list)');
        $this->actingAs($admin)->put("/admin/users/{$old->id}", ['name' => 'Cara Uy', 'id_number' => $old->id_number, 'email' => $old->email, 'role' => 'teacher', 'department' => 'CCS'])
            ->assertSessionHasNoErrors();
        $this->assertSame('CCS', $old->fresh()->department);
        $this->actingAs($admin)->put('/admin/users/'.User::where('id_number', 'T-1')->value('id'), [...$base, 'department' => 'CCS'])->assertSessionHasErrors('department');
    }

    public function test_admin_cannot_change_own_role_or_deactivate_self(): void
    {
        $admin = $this->admin(['name' => 'Ada Admin']);

        $this->actingAs($admin)->put("/admin/users/{$admin->id}", ['name' => 'Ada A. Admin', 'id_number' => $admin->id_number, 'email' => $admin->email, 'role' => 'student'])
            ->assertSessionHasNoErrors();
        $this->assertSame([User::ADMIN, 'Ada A. Admin'], [$admin->fresh()->role, $admin->fresh()->name]);

        $this->actingAs($admin)->post("/admin/users/{$admin->id}/deactivate")->assertSessionHasErrors('admin');
        $this->assertTrue($admin->fresh()->isActive());
        $this->actingAs($admin)->get('/admin/users')->assertDontSee('/admin/users/'.$admin->id.'/deactivate', false);
    }

    public function test_deactivated_users_cannot_log_in_and_are_signed_out(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $user = User::factory()->create(['email' => $this->email('ana'), 'password' => 'Secret@123']);

        // Logged in elsewhere when deactivated: signed out on the next request.
        $this->actingAs($user)->get('/dashboard')->assertOk();
        $userSession = session()->all(); // the student's browser
        $this->flushSession(); // the admin uses another browser
        $this->actingAs($admin)->post("/admin/users/{$user->id}/deactivate")->assertSessionHasNoErrors();
        $this->assertFalse($user->fresh()->isActive());
        $this->flushSession();
        $this->withSession($userSession)->actingAs($user->fresh())->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();

        // Can't log in, and only someone with the right password learns why.
        $this->post('/login', ['email' => $user->email, 'password' => 'Secret@123'])
            ->assertSessionHasErrors(['login' => 'Your account has been deactivated. Contact the administrator.']);
        $this->post('/login', ['email' => $user->email, 'password' => 'Wrong@123'])
            ->assertSessionHasErrors(['login' => 'These credentials do not match our records.']);
        $this->assertGuest();

        // Activated again: can log in.
        $this->flushSession();
        $this->actingAs($admin)->post("/admin/users/{$user->id}/activate");
        auth()->logout();
        $this->flushSession();
        $this->post('/login', ['email' => $user->email, 'password' => 'Secret@123'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user->fresh());

        $this->assertSame(1, Activity::where('event', Audit::USER_DEACTIVATED)->count());
        $this->assertSame(1, Activity::where('event', Audit::USER_ACTIVATED)->count());
    }

    /* ---------------- Import ---------------- */



    private function csv(array $lines, string $name = 'students.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, implode("\n", $lines)."\n");

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    private function import(string $type, UploadedFile $file)
    {
        return $this->actingAs($this->admin ??= $this->admin())->post('/admin/users/import', ['type' => $type, 'file' => $file]);
    }

    private ?User $admin = null;

    public function test_student_import_creates_valid_rows_and_skips_invalid_or_duplicate_ones(): void
    {
        $d = config('app.allowed_email_domain');
        User::factory()->create(['id_number' => '2023-00009', 'email' => "old@{$d}"]);

        $file = $this->csv([
            'ID Number,Name,Email,Course,Year Level,Section',
            "2023-00001,Ana Cruz,ana@{$d},bs it,3rd Year,Section 3-A",   // 2 ok: BSIT 3-A
            '2023-00002,Ben Lim,,BSIT,4,b',                               // 3 ok without an email
            '2023-00003,Cara Uy,,BSIT,1,A',                               // 4 ok: a second blank email isn't a duplicate
            "2023-00009,Old Person,new@{$d},BSIT,1,A",                    // 5 ID exists
            "2023-00001,Ana Again,ana2@{$d},BSIT,1,A",                    // 6 duplicate ID in file
            ',,,,,',                                                      // 7 blank: ignored
            '2023-00004,Dan Go,dan@gmail.com,BSIT,1,A',                   // 8 non-school email: imported without it, with an alert
            "2023-00005,,dina@{$d},BSIT,1,A",                             // 9 no name
            "2023-00006,Eli Tan,eli@{$d},BSCS,5,E",                       // 10 course, year, section not in the lists
            '2023-00007,Fe Ong,,BSIT,,C',                                 // 11 ok: year level is optional now
            "2023-00008,Gia Lee,ANA@{$d},BSIT,1,A",                       // 12 duplicate email in file (any case)
        ]);

        $this->import('student', $file)->assertRedirect(route('admin.users.import'))->assertSessionHas('status', '5 imported, 5 skipped.');

        $ana = User::where('id_number', '2023-00001')->sole();
        $this->assertSame([User::STUDENT, 'BSIT', 3, 'A', 'Ana Cruz'], [$ana->role, $ana->course, $ana->year_level, $ana->section, $ana->name]);
        $this->assertTrue(Hash::check(self::DEFAULT_PASSWORD, $ana->password));
        $this->assertNull(User::where('id_number', '2023-00002')->value('email'));
        $this->assertSame(['B', 4], [User::where('id_number', '2023-00002')->value('section'), User::where('id_number', '2023-00002')->value('year_level')]);
        $this->assertSame(4, User::whereNull('email')->count());
        $this->assertSame(['BSIT', null, 'C'], [User::where('id_number', '2023-00007')->value('course'), User::where('id_number', '2023-00007')->value('year_level'), User::where('id_number', '2023-00007')->value('section')]);
        $this->assertNull(User::where('id_number', '2023-00004')->value('email'));

        $page = $this->actingAs($this->admin)->get('/admin/users/import')->assertOk();
        $skipped = collect($page->viewData('result')['skipped'])->keyBy('row');
        $this->assertSame([5, 6, 9, 10, 12], $skipped->keys()->all());
        $this->assertSame(['ID number 2023-00009 already belongs to an account.'], $skipped[5]['reasons']);
        $this->assertSame(['Duplicate ID number: same as row 2 of this file.'], $skipped[6]['reasons']);
        $this->assertSame(['Name is required.'], $skipped[9]['reasons']);
        $this->assertSame([
            'Course "BSCS" isn\'t in the list (BSIT).',
            'Year level "5" isn\'t valid (use 1st Year to 4th Year).',
            'Section "E" isn\'t in the list (A, B, C, D).',
        ], $skipped[10]['reasons']);
        $this->assertSame(['Duplicate email: same as row 2 of this file.'], $skipped[12]['reasons']);
        $page->assertSee('5 imported, 5 skipped')->assertSee('Student import from students.csv');

        // FR-ADM-17: imported without a school email, listed in an alert.
        $alerts = collect($page->viewData('result')['email_alerts']);
        $this->assertSame([3, 4, 8, 11], $alerts->pluck('row')->all());
        $this->assertSame('No email in the file.', $alerts[0]['problem']);
        $this->assertSame('"dan@gmail.com" isn\'t a valid @'.$d.' address, so it was left out.', $alerts[2]['problem']);
        $page->assertSee('data-email-alert', false)->assertSee('4 imported students have no @'.$d.' email.')->assertSee('Dan Go');

        $log = Activity::where('event', Audit::USERS_IMPORTED)->sole();
        $this->assertSame('Student import from students.csv: 5 students imported, 5 rows skipped; 4 without a school email', $log->description);
        $this->assertSame(['student', 5, 5], [$log->getProperty('type'), $log->getProperty('imported'), $log->getProperty('skipped')]);
        $this->assertSame($this->admin->id, $log->causer_id);
    }

    public function test_teacher_import_gives_every_row_the_teacher_role(): void
    {
        $d = config('app.allowed_email_domain');
        $file = $this->csv([
            'ID Number,Name,Email,Department,Role,Course',  // a Role column (or any other) is ignored
            "T-001,Ben Lim,ben@{$d},Information Technology,Student,BSIT",  // department understood as BSIT
            'T-002,Cara Uy,,,,',
            'T-003,Dan Go,,Nursing,,',                                   // department not in the list
        ], 'teachers.xlsx.csv');

        $this->import('teacher', $file)->assertSessionHas('status', '2 imported, 1 skipped.');
        $skipped = $this->actingAs($this->admin)->get('/admin/users/import')->viewData('result')['skipped'];
        $this->assertSame(['Department "Nursing" isn\'t in the list (BSIT).'], $skipped[0]['reasons']);

        $ben = User::where('id_number', 'T-001')->sole();
        $this->assertSame([User::TEACHER, 'BSIT', null], [$ben->role, $ben->department, $ben->course]);
        $this->assertSame(User::TEACHER, User::where('id_number', 'T-002')->value('role'));
        $this->assertSame('Teacher import from teachers.xlsx.csv: 2 teachers imported, 1 row skipped; 1 without a school email', Activity::where('event', Audit::USERS_IMPORTED)->sole()->description);
    }

    /** FR-ADM-16: course / year level / section chosen on the page apply to every row. */
    public function test_student_import_can_set_course_year_and_section_for_every_row(): void
    {
        $d = config('app.allowed_email_domain');
        $file = $this->csv(['ID Number,Name,Email', "S-1,Ana Cruz,ana@{$d}", 'S-2,Ben Lim,', ',,']);

        // Course, year level and section are optional: without the dropdowns they stay empty.
        $this->import('student', $this->csv(['ID Number,Name,Email', 'S-0,Zed Uy,']))->assertSessionHas('status', '1 imported, 0 skipped.');
        $this->assertSame([null, null, null], [User::where('id_number', 'S-0')->value('course'), User::where('id_number', 'S-0')->value('year_level'), User::where('id_number', 'S-0')->value('section')]);
        Activity::query()->delete();

        $this->actingAs($this->admin)->post('/admin/users/import', ['type' => 'student', 'course' => 'BSIT', 'year_level' => 4, 'section' => 'B', 'file' => $file])
            ->assertSessionHas('status', '2 imported, 0 skipped.');
        foreach (['S-1', 'S-2'] as $id) {
            $this->assertSame('BSIT · 4th Year · Section B', User::where('id_number', $id)->sole()->academicLabel());
        }
        $this->assertStringContainsString('(set for every row: BSIT · 4th Year · Section B)', Activity::where('event', Audit::USERS_IMPORTED)->sole()->description);
        $this->actingAs($this->admin)->get('/admin/users/import')->assertSee('Set for every student:');

        // One chosen, the others from the file; a chosen value wins over the column.
        $file2 = $this->csv(['ID Number,Name,Year Level,Section', 'S-3,Cara Uy,2,C', 'S-4,Dan Go,3,A']);
        $this->actingAs($this->admin)->post('/admin/users/import', ['type' => 'student', 'course' => 'BSIT', 'section' => 'D', 'file' => $file2])
            ->assertSessionHas('status', '2 imported, 0 skipped.');
        $this->assertSame([2, 'D'], [User::where('id_number', 'S-3')->value('year_level'), User::where('id_number', 'S-3')->value('section')]);

        // Only listed values are accepted; teachers ignore them.
        $this->actingAs($this->admin)->post('/admin/users/import', ['type' => 'student', 'course' => 'BSCS', 'year_level' => 9, 'section' => 'Z', 'file' => $file])
            ->assertSessionHasErrors(['course', 'year_level', 'section']);
        $this->actingAs($this->admin)->post('/admin/users/import', ['type' => 'teacher', 'course' => 'BSIT', 'file' => $this->csv(['ID Number,Name', 'T-9,Fe Ong'])]);
        $this->assertNull(User::where('id_number', 'T-9')->value('course'));

        $this->actingAs($this->admin)->get('/admin/users/import?type=student')
            ->assertSee('data-student-only class="rounded-lg border border-slate-200 p-4"', false)
            ->assertSee('<option value="">Not set</option>', false);
        $this->actingAs($this->admin)->get('/admin/users/import?type=teacher')->assertSee('data-student-only class="rounded-lg border border-slate-200 p-4 hidden"', false);
    }

    public function test_import_headings_are_matched_loosely(): void
    {
        $d = config('app.allowed_email_domain');
        $file = $this->csv([
            "\u{FEFF}  student id. , FULL_NAME,E-mail Address (optional),Program, yr. level ,SEC",
            "S-1,Ana Cruz,ana@{$d},BSIT,2nd,c",
        ]);

        $this->import('student', $file)->assertSessionHas('status', '1 imported, 0 skipped.');
        $this->assertSame(['Ana Cruz', "ana@{$d}", 2, 'C'], [User::where('id_number', 'S-1')->value('name'), User::where('id_number', 'S-1')->value('email'),
            User::where('id_number', 'S-1')->value('year_level'), User::where('id_number', 'S-1')->value('section')]);

        foreach (['ID No.', 'id_number', 'Student No', 'ID NUMBER'] as $heading) {
            $this->assertSame('id_number', collect(\App\Services\UserImportService::HEADING_ALIASES)
                ->search(fn ($aliases) => in_array(\App\Services\UserImportService::normalizeHeading($heading), array_map([\App\Services\UserImportService::class, 'normalizeHeading'], $aliases), true)));
        }
    }

    /** FR-ADM-18: titles, dates and blank rows above the headings are skipped. */
    public function test_import_finds_the_heading_row_below_titles_and_dates(): void
    {
        $d = config('app.allowed_email_domain');
        $file = $this->csv([
            'MARY THE QUEEN COLLEGE OF QUEZON CITY,,,',
            'BSIT 4-B Class List,,,',
            'JULY 9.2026,JULY 16.2026,46235,August 15.2026',
            ',,,',
            'No.,Student No.,Name of Student,Email',     // row 5: the heading row
            "1,2023-10001,Ana Cruz,ana@{$d}",
            '2,2023-10002,Ben Lim,',
            ',,,',
            "3,,Cara Uy,cara@{$d}",                     // row 9: no ID number
        ]);

        $this->actingAs($this->admin ??= $this->admin())->post('/admin/users/import', ['type' => 'student', 'course' => 'BSIT', 'year_level' => 4, 'section' => 'B', 'file' => $file])
            ->assertSessionHas('status', '2 imported, 1 skipped.');
        $this->assertSame('BSIT · 4th Year · Section B', User::where('id_number', '2023-10001')->sole()->academicLabel());
        $this->assertSame('Ben Lim', User::where('id_number', '2023-10002')->value('name'));

        // Row numbers still match the spreadsheet.
        $result = $this->actingAs($this->admin)->get('/admin/users/import')->viewData('result');
        $this->assertSame([9], array_column($result['skipped'], 'row'));
        $this->assertSame([7], array_column($result['email_alerts'], 'row'));
    }

    public function test_import_explains_when_no_heading_row_is_found(): void
    {
        // Like an attendance sheet: dates and numbers, no ID Number or Name headings.
        $this->import('student', $this->csv(['JULY 9.2026,JULY 16.2026,46235', '46242,46256,46263']))
            ->assertSessionHasErrors(['file' => 'Missing required columns: "ID Number", "Name". '
                .'Found in your file: "JULY 9.2026", "JULY 16.2026", "46235". '
                .'Expected for a Student import: ID Number*, Name*, Email (* = required). No heading row was found in the first 20 rows.']);

        // The closest row is reported, with its row number.
        $this->import('student', $this->csv(['Class list,', 'Student ID,Remarks', '1,x']))
            ->assertSessionHasErrors(['file' => 'Missing required column: "Name". Found in your file (row 2): "Student ID", "Remarks". '
                .'Expected for a Student import: ID Number*, Name*, Email (* = required).']);
    }

    public function test_import_rejects_bad_files_and_lists_found_and_expected_headings(): void
    {
        $this->import('student', $this->csv(['Student ID,Email,Remarks', 'A,,']))
            ->assertSessionHasErrors(['file' => 'Missing required column: "Name". '
                .'Found in your file: "Student ID", "Email", "Remarks". '
                .'Expected for a Student import: ID Number*, Name*, Email (* = required).']);

        $this->import('teacher', $this->csv(['Name', 'Ben']))
            ->assertSessionHasErrors(['file' => 'Missing required column: "ID Number". Found in your file: "Name". Expected for a Teacher import: ID Number*, Name*, Email, Department (* = required).']);

        $this->actingAs($this->admin)->post('/admin/users/import', ['file' => $this->csv(['ID Number,Name', '1,A'])])->assertSessionHasErrors('type');
        $this->import('admin', $this->csv(['ID Number,Name', '1,A']))->assertSessionHasErrors('type');
        $this->import('student', UploadedFile::fake()->create('photo.png', 10, 'image/png'))->assertSessionHasErrors('file');
        $this->import('teacher', $this->csv(['ID Number,Name']))->assertSessionHasErrors(['file' => 'The file has no data rows. Fill in the template below the heading row.']);

        config(['app.import_default_password' => 'weak']);
        $this->import('teacher', $this->csv(['ID Number,Name', '1,A']))->assertSessionHasErrors('file');
        $this->assertSame(1, User::count());
    }

    public function test_import_templates_download_per_type_and_the_page_switches_them(): void
    {
        $admin = $this->admin();
        $read = fn ($res) => trim(file_get_contents($res->getFile()->getPathname()));

        $student = $this->actingAs($admin)->get('/admin/users/import/template/student.csv')->assertOk();
        $this->assertStringContainsString('attendease-student-import-template.csv', $student->headers->get('content-disposition'));
        $this->assertSame('"ID Number","Name","Email"', $read($student));
        $this->assertSame('"ID Number","Name","Email","Department"', $read($this->actingAs($admin)->get('/admin/users/import/template/teacher.csv')));

        foreach (['student', 'teacher'] as $type) {
            $xlsx = $this->actingAs($admin)->get("/admin/users/import/template/{$type}.xlsx")->assertOk();
            $this->assertStringStartsWith('PK', file_get_contents($xlsx->getFile()->getPathname())); // a zip (xlsx)
        }
        $this->actingAs($admin)->get('/admin/users/import/template/admin.csv')->assertNotFound();

        // The page has the required "Import as" choice; only the chosen type's template is shown.
        $this->actingAs($admin)->get('/admin/users/import')->assertOk()
            ->assertSee('Import as')->assertSee('<option value="" selected>Choose…</option>', false)
            ->assertSee('data-template-for="student" class="hidden"', false)->assertSee('data-template-for="teacher" class="hidden"', false);
        $this->actingAs($admin)->get('/admin/users/import?type=teacher')
            ->assertSee('data-template-for="teacher" class=""', false)->assertSee('data-template-for="student" class="hidden"', false)
            ->assertSee('Download Teacher Excel template');
    }

    /* ---------------- Optional email and login by ID number (FR-ADM-13) ---------------- */

    public function test_users_without_an_email_log_in_with_their_id_number(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => null, 'id_number' => '2023-00042', 'password' => 'Secret@123']);
        $withEmail = User::factory()->create(['id_number' => '2023-00043', 'password' => 'Secret@123']);

        $this->get('/login')->assertSee('ID Number or School Email');
        $this->post('/login', ['login' => ' 2023-00042 ', 'password' => 'Secret@123'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->post('/logout');

        // Either works for an account that has both.
        $this->post('/login', ['login' => '2023-00043', 'password' => 'Secret@123'])->assertRedirect(route('dashboard'));
        $this->post('/logout');
        $this->post('/login', ['login' => strtoupper($withEmail->email), 'password' => 'Secret@123'])->assertRedirect(route('dashboard'));
        $this->post('/logout');

        $this->post('/login', ['login' => '2023-00042', 'password' => 'Wrong@123'])
            ->assertSessionHasErrors(['login' => 'These credentials do not match our records.']);
        $this->assertGuest();
    }

    public function test_email_is_optional_but_must_be_a_unique_school_address_when_given(): void
    {
        $admin = $this->admin();
        $d = config('app.allowed_email_domain');
        $base = ['role' => 'teacher', 'name' => 'No Mail'];

        $this->actingAs($admin)->post('/admin/users', [...$base, 'id_number' => 'T-1', 'email' => ''])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/admin/users', [...$base, 'id_number' => 'T-2'])->assertSessionHasNoErrors(); // second blank: fine
        $this->assertSame(2, User::whereNull('email')->count());
        $this->assertSame(0, User::where('email', '')->count());

        $this->actingAs($admin)->post('/admin/users', [...$base, 'id_number' => 'T-3', 'email' => 'x@gmail.com'])->assertSessionHasErrors('email');
        $this->actingAs($admin)->post('/admin/users', [...$base, 'id_number' => 'T-4', 'email' => $admin->email])->assertSessionHasErrors('email');

        $this->actingAs($admin)->get('/admin/users')->assertSee('No email')->assertSee('data-no-email', false);
        $this->assertStringContainsString('(T-1) · no email', Activity::where('event', Audit::USER_ADDED)->first()->description);

        // A student can clear their own email too; class notices then stay in-app only.
        $student = User::factory()->create();
        $this->actingAs($student)->put('/profile', ['name' => $student->name, 'id_number' => $student->id_number, 'email' => ''])->assertSessionHasNoErrors();
        $this->assertNull($student->fresh()->email);
        $this->assertSame(['database'], (new \App\Notifications\AbsenceAlert(\App\Models\AttendanceSession::factory()->create()))->via($student->fresh()));
    }

    /* ---------------- Activity Log ---------------- */

    public function test_attendance_edits_are_logged_with_old_and_new_status(): void
    {
        Notification::fake();
        $class = $this->makeClass(null, ['subject' => 'ITE101', 'section' => 'BSIT-1A']);
        $student = $this->enroll($class, User::factory()->create(['name' => 'Ana Cruz', 'id_number' => '2023-00001']));
        $session = AttendanceSession::factory()->for($class, 'schoolClass')->create(['date' => '2026-11-12']);
        AttendanceRecord::factory()->create(['attendance_session_id' => $session->id, 'student_id' => $student->id, 'status' => 'absent']);

        $this->actingAs($class->teacher)->post("/sessions/{$session->id}/records", ['student_id' => $student->id, 'status' => 'present']);
        $this->actingAs($class->teacher)->post("/sessions/{$session->id}/records", ['student_id' => $student->id, 'status' => 'present']); // no change: not logged

        $log = Activity::where('event', Audit::ATTENDANCE_EDITED)->sole();
        $this->assertSame('Ana Cruz (2023-00001) · ITE101 BSIT-1A · Nov 12, 2026: Absent → Present (manual edit)', $log->description);
        $this->assertSame(['absent', 'present'], [$log->getProperty('old'), $log->getProperty('new')]);
        $this->assertSame($class->teacher->id, $log->causer_id);
        $this->assertSame(User::TEACHER, $log->getProperty('causer_role'));
    }

    public function test_activity_log_page_filters_newest_first_and_is_read_only(): void
    {
        $admin = $this->admin(['name' => 'Ada Admin']);
        $this->actingAs($admin);

        $this->travelTo('2026-11-01 09:00:00');
        Audit::log(Audit::USER_ADDED, 'Added Student First');
        $this->travelTo('2026-11-10 09:00:00');
        Audit::log(Audit::USER_DEACTIVATED, 'Deactivated Student Second');
        $this->travelTo('2026-11-15 09:00:00');
        Audit::log(Audit::USER_ADDED, 'Added Student Third');

        $details = fn (string $q = '') => $this->get('/admin/activity?'.$q)->assertOk()->viewData('entries')->pluck('description')->all();

        $this->assertSame(['Added Student Third', 'Deactivated Student Second', 'Added Student First'], $details());
        $this->assertSame(['Added Student Third', 'Added Student First'], $details('action=user_added'));
        $this->assertSame(['Deactivated Student Second'], $details('from=2026-11-05&to=2026-11-12'));
        $this->assertSame(['Added Student Third'], $details('from=2026-11-15&to=2026-11-15'));
        $this->get('/admin/activity?from=2026-11-15&to=2026-11-01')->assertSessionHasErrors('to');

        $this->get('/admin/activity')->assertSeeInOrder(['Date &amp; Time', 'User', 'Action', 'Details'], false)
            ->assertSee('Ada Admin')->assertSee('Admin')->assertSee('User deactivated')->assertSee('Nov 15, 2026');

        // No way to change or remove entries.
        $id = Activity::first()->id;
        $this->put("/admin/activity/{$id}")->assertNotFound();
        $this->delete("/admin/activity/{$id}")->assertNotFound();
        $this->assertSame(3, Activity::count());
    }

    public function test_make_admin_command_promotes_an_existing_account(): void
    {
        $user = User::factory()->deactivated()->create(['email' => $this->email('first')]);

        $this->artisan('attendease:make-admin', ['email' => 'FIRST@'.config('app.allowed_email_domain')])->assertSuccessful();
        $this->assertTrue($user->fresh()->isAdmin());
        $this->assertTrue($user->fresh()->isActive());
        $this->assertSame(1, Activity::where('event', Audit::ROLE_CHANGED)->count());

        $this->artisan('attendease:make-admin', ['email' => 'nobody@x.com'])->assertFailed();
    }

    /* ---------------- Course, year level and section (FR-ADM-10..12) ---------------- */

    private function student(string $name, ?int $year, ?string $section = null, array $attrs = []): User
    {
        return User::factory()->create(['name' => $name, 'course' => $year ? 'BSIT' : null, 'year_level' => $year, 'section' => $section, ...$attrs]);
    }

    public function test_users_page_has_year_tabs_with_counts_that_work_with_the_filters(): void
    {
        $admin = $this->admin(['name' => 'Ada Admin']);
        $this->student('Ana Cruz', 1, 'A');
        $this->student('Ben Lim', 1, 'B');
        $this->student('Cara Uy', 2, 'A', ['deactivated_at' => now()]);
        $this->student('Dan Go', 4, 'B');
        $this->student('Eve Sy', null); // no year yet: only under All
        User::factory()->teacher()->create(['name' => 'Fe Ong', 'department' => 'CCS']);

        $page = $this->actingAs($admin)->get('/admin/users')->assertOk();
        $this->assertSame(['all' => 7, 'year1' => 2, 'year2' => 1, 'year3' => 0, 'year4' => 1, 'teachers' => 1], $page->viewData('counts')->all());
        $page->assertSeeInOrder(['All', '7', '1st Year', '2', '2nd Year', '1', '3rd Year', '0', '4th Year', '1', 'Teachers', '1'])
            ->assertSee('BSIT · 1st Year · Section A')->assertSee('CCS')
            ->assertDontSee('id="user-section"', false); // Section filter only on a year tab

        $names = fn (string $q) => $this->actingAs($admin)->get('/admin/users?'.$q)->assertOk()->viewData('users')->pluck('name')->all();

        $this->assertSame(['Ana Cruz', 'Ben Lim'], $names('tab=year1'));
        $this->assertSame(['Dan Go'], $names('tab=year4'));
        $this->assertSame(['Fe Ong'], $names('tab=teachers'));
        $this->assertSame(['Ben Lim'], $names('tab=year1&q=lim'));             // tab + search
        $this->assertSame([], $names('tab=year2&status=active'));             // tab + status
        $this->assertSame([], $names('tab=teachers&role=student'));           // tab + role

        // Counts follow the search, role and status filters.
        $counts = $this->actingAs($admin)->get('/admin/users?status=active')->viewData('counts')->all();
        $this->assertSame([6, 0], [$counts['all'], $counts['year2']]);

        // The tab links keep the filters.
        $this->actingAs($admin)->get('/admin/users?q=a&status=active')
            ->assertSee(e(route('admin.users.index', ['q' => 'a', 'status' => 'active', 'tab' => 'year1'])), false);

        $this->actingAs($admin)->get('/admin/users?tab=year9')->assertSessionHasErrors('tab');
    }

    public function test_section_filter_appears_on_a_year_tab_and_paging_keeps_the_tab(): void
    {
        $admin = $this->admin();
        $this->student('Ana Cruz', 1, 'A');
        $this->student('Ben Lim', 1, 'B');
        $this->student('Carl Reyes', 1, null);
        $this->student('Dan Go', 2, 'A');

        $page = $this->actingAs($admin)->get('/admin/users?tab=year1')->assertOk()
            ->assertSee('id="user-section"', false)->assertSee('Section D')->assertSee('No section')
            ->assertSee('<input type="hidden" name="tab" value="year1">', false);

        $names = fn (string $q) => $this->actingAs($admin)->get('/admin/users?'.$q)->viewData('users')->pluck('name')->all();
        $this->assertSame(['Ana Cruz'], $names('tab=year1&section=A'));
        $this->assertSame(['Carl Reyes'], $names('tab=year1&section=__none'));
        $this->assertSame(['Ana Cruz', 'Dan Go'], array_values(array_intersect($names('tab=all&section=A'), ['Ana Cruz', 'Dan Go']))); // ignored outside a year tab
        $this->assertCount(5, $names('tab=all&section=A'));

        foreach (range(1, 25) as $i) {
            $this->student('Zed '.$i, 1, 'C');
        }
        $p = $this->actingAs($admin)->get('/admin/users?tab=year1&section=C')->viewData('users');
        $this->assertSame([20, 25], [$p->count(), $p->total()]);
        $this->assertStringContainsString('tab=year1', $p->nextPageUrl());
        $this->assertStringContainsString('section=C', $p->nextPageUrl());
        $this->assertSame(5, $this->actingAs($admin)->get('/admin/users?tab=year1&section=C&page=2')->viewData('users')->count());
    }

    public function test_conversion_command_converts_typed_values_and_lists_the_rest(): void
    {
        $make = fn (string $name, ?string $course, ?string $year, ?string $section) => User::factory()->create(compact('name', 'course', 'year', 'section'));
        $a = $make('Ana Cruz', 'Bachelor of Science Information Technology', '4TH', 'B');
        $b = $make('Ben Lim', null, '4TH YEAR', 'Section 4B');
        $c = $make('Cara Uy', null, null, 'Section 4-B');
        $d = $make('Dan Go', 'BSCS', '2', 'A');                  // course not in the list
        $e = $make('Eve Sy', 'BSIT', '3rd', 'Section 4-B');      // year doesn't match the section
        $f = $make('Fe Ong', null, null, null);                  // nothing to convert
        $teacher = User::factory()->teacher()->create(['year' => '4TH']); // teachers are left alone

        $this->artisan('attendease:convert-student-info', ['--dry-run' => true])
            ->expectsOutputToContain('[dry run] Would convert 3 students.')
            ->expectsOutputToContain('2 students couldn\'t be converted')
            ->assertSuccessful();
        $this->assertSame('4TH', $a->fresh()->year); // nothing saved

        $this->artisan('attendease:convert-student-info')
            ->expectsOutputToContain('Converted 3 students.')
            ->expectsOutputToContain('Course "BSCS" isn\'t in the list (BSIT).')
            ->expectsOutputToContain('Year "3rd" doesn\'t match the year in section "Section 4-B".')
            ->assertSuccessful();

        $this->assertSame(['BSIT', 4, 'B', null], [$a->fresh()->course, $a->fresh()->year_level, $a->fresh()->section, $a->fresh()->year]);
        $this->assertSame([null, 4, 'B'], [$b->fresh()->course, $b->fresh()->year_level, $b->fresh()->section]);
        $this->assertSame([4, 'B'], [$c->fresh()->year_level, $c->fresh()->section]);
        $this->assertSame(['BSCS', '2', 'A'], [$d->fresh()->course, $d->fresh()->year, $d->fresh()->section]); // untouched
        $this->assertTrue($d->fresh()->needsInfoUpdate());
        $this->assertSame('3rd', $e->fresh()->year);
        $this->assertSame('4TH', $teacher->fresh()->year);
        $this->assertSame('BSIT · 4th Year · Section B', $a->fresh()->academicLabel());

        // Running it again changes nothing and lists the same two.
        $this->artisan('attendease:convert-student-info')->expectsOutputToContain('Converted 0 students.')->assertSuccessful();

        // Unconverted students are flagged in the list and on the Edit form.
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/users')->assertSee('Needs update — was: Course: BSCS · Year: 2 · Section: A');
        $this->actingAs($admin)->get("/admin/users/{$d->id}/edit")->assertSee('Old typed values: Course: BSCS · Year: 2 · Section: A');

        // Fixing by hand clears the old year text.
        $this->actingAs($admin)->put("/admin/users/{$d->id}", ['name' => 'Dan Go', 'id_number' => $d->id_number, 'email' => $d->email, 'role' => 'student',
            'course' => 'BSIT', 'year_level' => 2, 'section' => 'A'])->assertSessionHasNoErrors();
        $this->assertFalse($d->fresh()->needsInfoUpdate());
        $this->assertNull($d->fresh()->year);
    }

    public function test_students_pick_course_year_and_section_on_their_profile(): void
    {
        $student = User::factory()->create(['course' => 'BS IT', 'year' => '4TH', 'section' => 'Section 4-B']);
        $base = ['name' => $student->name, 'email' => $student->email, 'id_number' => $student->id_number];

        $this->actingAs($student)->get('/profile')->assertOk()
            ->assertSee('<select id="f_course" name="course"', false)->assertSee('4th Year')->assertSee('Section D')
            ->assertSee('Old typed values');

        // Saving without picking keeps the old text for the admin to see.
        $this->actingAs($student)->put('/profile', $base)->assertSessionHasNoErrors();
        $this->assertSame('4TH', $student->fresh()->year);

        $this->actingAs($student)->put('/profile', [...$base, 'course' => 'XYZ', 'year_level' => 7, 'section' => 'Z'])
            ->assertSessionHasErrors(['course', 'year_level', 'section']);

        $this->actingAs($student)->put('/profile', [...$base, 'course' => 'BSIT', 'year_level' => 4, 'section' => 'B'])->assertSessionHasNoErrors();
        $this->assertSame(['BSIT', 4, 'B', null], [$student->fresh()->course, $student->fresh()->year_level, $student->fresh()->section, $student->fresh()->year]);

        // Teachers have a department instead (FR-ADM-19: a dropdown).
        $teacher = User::factory()->teacher()->create();
        $this->actingAs($teacher)->get('/profile')->assertSee('<select id="f_department" name="department"', false)->assertDontSee('name="year_level"', false);
        $this->actingAs($teacher)->put('/profile', ['name' => $teacher->name, 'id_number' => $teacher->id_number, 'email' => $teacher->email, 'department' => 'Nursing'])
            ->assertSessionHasErrors('department');
        $this->actingAs($teacher)->put('/profile', ['name' => $teacher->name, 'id_number' => $teacher->id_number, 'email' => $teacher->email, 'department' => 'BSIT'])
            ->assertSessionHasNoErrors();
        $this->assertSame('BSIT', $teacher->fresh()->department);
    }

    public function test_report_shows_the_compact_course_year_section(): void
    {
        $class = $this->makeClass();
        $this->enroll($class, User::factory()->create(['course' => 'BSIT', 'year_level' => 4, 'section' => 'B']));

        $res = $this->actingAs($class->teacher)->get("/classes/{$class->id}/report")->assertOk()->assertSee('BSIT 4-B');
        $this->assertStringContainsString('<td>BSIT 4-B</td>', view('pdf.attendance-report', $res->original->getData())->render());
        $this->actingAs($class->teacher)->get("/classes/{$class->id}/students")->assertSee('BSIT 4-B');
    }
}
