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
        $pages = ['/admin', '/admin/users', '/admin/users/create', '/admin/users/import', '/admin/activity', '/admin/users/import/template.csv'];

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
            'name' => 'Juan Dela Cruz', 'id_number' => 'T-100', 'email' => ' JUAN@'.config('app.allowed_email_domain'), 'role' => 'teacher', 'department' => 'CCS',
        ])->assertRedirect(route('admin.users.index'));

        $juan = User::where('id_number', 'T-100')->first();
        $this->assertSame([User::TEACHER, $this->email('juan'), 'CCS'], [$juan->role, $juan->email, $juan->department]);
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
        $user = User::factory()->create(['name' => 'Ana Cruz', 'id_number' => 'S-1', 'email' => $this->email('ana'), 'section' => 'A']);

        $this->actingAs($admin)->get("/admin/users/{$user->id}/edit")->assertOk()->assertSee('Ana Cruz');

        $this->actingAs($admin)->put("/admin/users/{$user->id}", [
            'name' => 'Ana Cruz', 'id_number' => 'S-1', 'email' => $this->email('ana'), 'section' => 'B', 'role' => 'teacher', 'password' => 'NewPass@123',
        ])->assertRedirect(route('admin.users.index'));

        $user->refresh();
        $this->assertSame(['B', User::TEACHER], [$user->section, $user->role]);
        $this->assertTrue(Hash::check('NewPass@123', $user->password));

        $this->assertSame('Ana Cruz (S-1): Section: A → B; password reset', Activity::where('event', Audit::USER_EDITED)->sole()->description);
        $this->assertSame('Ana Cruz (S-1): Student → Teacher', Activity::where('event', Audit::ROLE_CHANGED)->sole()->description);

        // Unique checks ignore the user's own values; another user's are rejected.
        User::factory()->create(['id_number' => 'S-2']);
        $this->actingAs($admin)->put("/admin/users/{$user->id}", ['name' => 'Ana Cruz', 'id_number' => 'S-2', 'email' => $this->email('ana'), 'role' => 'teacher'])
            ->assertSessionHasErrors('id_number');

        // Saving with nothing changed logs nothing.
        $this->actingAs($admin)->put("/admin/users/{$user->id}", ['name' => 'Ana Cruz', 'id_number' => 'S-1', 'email' => $this->email('ana'), 'section' => 'B', 'role' => 'teacher']);
        $this->assertSame(2, Activity::count());
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
            ->assertSessionHasErrors(['email' => 'Your account has been deactivated. Contact the administrator.']);
        $this->post('/login', ['email' => $user->email, 'password' => 'Wrong@123'])
            ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
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

    private function csv(array $lines): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, implode("\n", $lines)."\n");

        return new UploadedFile($path, 'students.csv', 'text/csv', null, true);
    }

    public function test_import_creates_valid_rows_and_skips_invalid_or_duplicate_ones(): void
    {
        $admin = $this->admin();
        $d = config('app.allowed_email_domain');
        User::factory()->create(['id_number' => '2023-00009', 'email' => "old@{$d}"]);

        $file = $this->csv([
            'Role,ID Number,Name,Email,Course,Year,Section,Department',
            "Student,2023-00001,Ana Cruz,ana@{$d},BSIT,3rd Year,BSIT-3A,",          // 2 ok
            "teacher,T-001,Ben Lim,BEN@{$d},,,,CCS",                                 // 3 ok
            "Student,2023-00009,Old Person,new@{$d},,,,",                            // 4 ID exists
            "Student,2023-00001,Ana Again,ana2@{$d},,,,",                            // 5 duplicate in file
            "Admin,A-1,Sneaky,sneaky@{$d},,,,",                                      // 6 role not allowed
            ',,,,,,,',                                                               // 7 blank: ignored
            "Student,2023-00002,Cara Uy,cara@gmail.com,,,,",                         // 8 wrong domain
            "Student,2023-00003,,dina@{$d},,,,",                                     // 9 no name
        ]);

        $this->actingAs($admin)->post('/admin/users/import', ['file' => $file])
            ->assertRedirect(route('admin.users.import'))
            ->assertSessionHas('status', '2 imported, 5 skipped.');

        $ana = User::where('id_number', '2023-00001')->sole();
        $this->assertSame([User::STUDENT, 'BSIT-3A', 'Ana Cruz'], [$ana->role, $ana->section, $ana->name]);
        $this->assertTrue(Hash::check(self::DEFAULT_PASSWORD, $ana->password));
        $this->assertSame([User::TEACHER, "ben@{$d}"], [User::where('id_number', 'T-001')->value('role'), User::where('id_number', 'T-001')->value('email')]);
        $this->assertSame(0, User::where('id_number', 'A-1')->count());

        $page = $this->actingAs($admin)->get('/admin/users/import')->assertOk();
        $skipped = collect($page->viewData('result')['skipped'])->keyBy('row');
        $this->assertSame([4, 5, 6, 8, 9], $skipped->keys()->all());
        $this->assertSame(['ID number 2023-00009 already belongs to an account.'], $skipped[4]['reasons']);
        $this->assertSame(['Duplicate ID number: same as row 2 of this file.'], $skipped[5]['reasons']);
        $this->assertStringContainsString('Use Student or Teacher', $skipped[6]['reasons'][0]);
        $page->assertSee('2 imported, 5 skipped')->assertSee('Duplicate ID number: same as row 2 of this file.');

        $log = Activity::where('event', Audit::USERS_IMPORTED)->sole();
        $this->assertSame('Imported 2 users (1 student, 1 teacher) from students.csv; 5 rows skipped', $log->description);
        $this->assertSame($admin->id, $log->causer_id);
    }

    public function test_import_rejects_bad_files_and_needs_a_default_password(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/users/import', ['file' => $this->csv(['Name,Email', 'A,b'])])
            ->assertSessionHasErrors(['file' => 'Missing column "Role", "Id Number". Download the template and keep its heading row.']);
        $this->actingAs($admin)->post('/admin/users/import', ['file' => UploadedFile::fake()->create('photo.png', 10, 'image/png')])
            ->assertSessionHasErrors('file');

        config(['app.import_default_password' => 'weak']);
        $this->actingAs($admin)->post('/admin/users/import', ['file' => $this->csv(['Role,ID Number,Name,Email'])])
            ->assertSessionHasErrors('file');
        $this->assertSame(1, User::count());
    }

    public function test_import_template_downloads_with_the_right_headings(): void
    {
        $admin = $this->admin();

        $csv = $this->actingAs($admin)->get('/admin/users/import/template.csv')->assertOk();
        $this->assertStringContainsString('attendease-user-import-template.csv', $csv->headers->get('content-disposition'));
        $this->assertSame('"Role","ID Number","Name","Email","Course","Year","Section","Department"', trim(file_get_contents($csv->getFile()->getPathname())));

        $xlsx = $this->actingAs($admin)->get('/admin/users/import/template.xlsx')->assertOk();
        $this->assertStringStartsWith('PK', file_get_contents($xlsx->getFile()->getPathname())); // a zip (xlsx)
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
}
