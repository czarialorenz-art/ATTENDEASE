<?php

use App\Models\AttendanceSession;
use Illuminate\Support\Facades\Schedule;

/*
 | FR-ATT-16: permanently delete attendance sessions archived more than a year ago,
 | with their records, scans and flags. Needs the scheduler running
 | (cron: * * * * * php artisan schedule:run). The Sessions page also cleans up
 | its own class as a backup.
 */
Schedule::command('model:prune', ['--model' => [AttendanceSession::class]])->daily();
