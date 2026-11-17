<?php

use App\Models\AttendanceSession;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

/*
 | FR-ATT-16: permanently delete attendance sessions archived more than a year ago,
 | with their records, scans and flags. Needs the scheduler running
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
