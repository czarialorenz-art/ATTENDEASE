<?php

use App\Models\AttendanceSession;
use App\Models\User;
use App\Support\Audit;
use App\Support\StudentInfo;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

/*
 | FR-ATT-16: permanently delete attendance sessions archived more than a year ago,
 | with their records and scans. Needs the scheduler running
 | (cron: * * * * * php artisan schedule:run). The Sessions page also cleans up
 | its own class as a backup.
 */
Schedule::command('model:prune', ['--model' => [AttendanceSession::class]])->daily();

/*
 | FR-ADM-01: give an existing account the Admin role (the first admin has to be made here).
 |   php artisan attendease:make-admin someone@mqcqc.ph.education
 */
Artisan::command('attendease:make-admin {email : Email of an existing account}', function (string $email) {
    $user = User::where('email', Str::lower(trim($email)))->first();

    if (! $user) {
        $this->error("No account uses {$email}. Sign up with it first, then run this again.");

        return 1;
    }

    $oldRole = $user->role;
    $wasDeactivated = ! $user->isActive();
    $user->forceFill(['role' => User::ADMIN, 'deactivated_at' => null])->save();

    if ($oldRole !== User::ADMIN) {
        Audit::log(Audit::ROLE_CHANGED, Audit::who($user).': '.(User::ROLES[$oldRole] ?? $oldRole).' → Admin (artisan attendease:make-admin)', $user,
            ['old' => $oldRole, 'new' => User::ADMIN]);
    }
    if ($wasDeactivated) {
        Audit::log(Audit::USER_ACTIVATED, 'Activated Admin '.Audit::who($user).' (artisan attendease:make-admin)', $user);
    }

    $this->info("{$user->name} is now an Admin. Log in and open /admin.");

    return 0;
})->purpose('Give an existing account the Admin role');

/*
 | FR-ADM-10: one-time conversion of students' free-text course / year / section into a course
 | code, year level (1-4) and section letter. Safe to run again: converted students are skipped.
 | Students it can't convert are listed and keep their old text, to be fixed by hand
 | (Admin → User Management → Edit). Use --dry-run first to preview.
 */
Artisan::command('attendease:convert-student-info {--dry-run : Show the result without saving anything}', function () {
    $dry = (bool) $this->option('dry-run');
    $students = User::where('role', User::STUDENT)->orderBy('name')->get()->filter->needsInfoUpdate();

    $converted = [];
    $failed = [];

    foreach ($students as $student) {
        $result = StudentInfo::convert($student->course, $student->year, $student->section);
        $old = $student->legacyInfo();

        if ($result['problems']) {
            $failed[] = [$student->id_number, $student->name, $old, implode(' ', $result['problems'])];

            continue;
        }

        $new = StudentInfo::full($result['course'], $result['year_level'], $result['section']) ?? '(empty)';
        $converted[] = [$student->id_number, $student->name, $old, $new];

        if (! $dry) {
            $student->forceFill([
                'course' => $result['course'],
                'year_level' => $result['year_level'],
                'section' => $result['section'],
                'year' => null,
            ])->save();
        }
    }

    $this->info(($dry ? '[dry run] Would convert ' : 'Converted ').count($converted).' '.Str::plural('student', count($converted)).'.');
    if ($converted) {
        $this->table(['ID number', 'Name', 'Old values', 'New'], $converted);
    }

    if ($failed) {
        $this->newLine();
        $this->warn(count($failed).' '.Str::plural('student', count($failed)).' couldn\'t be converted. Fix them in Admin → User Management → Edit:');
        $this->table(['ID number', 'Name', 'Old values', 'Problem'], $failed);
    } else {
        $this->info('Nothing left to fix by hand.');
    }

    if ($dry) {
        $this->comment('Nothing was saved. Run again without --dry-run to convert.');
    }

    return 0;
})->purpose('Convert students\' typed course/year/section into course code, year level and section letter');
