<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\UserImportController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AttendanceCorrectionController;
use App\Http\Controllers\AttendanceHistoryController;
use App\Http\Controllers\AttendanceSessionController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\ClassController;
use App\Http\Controllers\ClassScheduleController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ScanController;
use Illuminate\Support\Facades\Route;

/* ---------------------------------------------------------------------
 | Public (guest) pages
 * --------------------------------------------------------------------- */
Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store']);

    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);

    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.store');
});

/* ---------------------------------------------------------------------
 | Everything else sits behind auth (FR-ACL-01)
 * --------------------------------------------------------------------- */
Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::redirect('/', '/dashboard');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/dashboard/today', [DashboardController::class, 'today'])->middleware('role:teacher')->name('dashboard.today'); // polled by the dashboard
    Route::get('/dashboard/today/mine', [DashboardController::class, 'todayStudent'])->middleware('role:student')->name('dashboard.today.student');

    // Account
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/password', [PasswordController::class, 'update'])->name('password.update');

    // Notifications (FR-NOTIF-01)
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');

    // Classes and enrollment (section 4)
    // FR-ACL-03: only Teachers create classes and only Students join them (class pages check the role through the policies).
    Route::get('/classes/create', [ClassController::class, 'create'])->middleware('role:teacher')->name('classes.create');
    Route::post('/classes', [ClassController::class, 'store'])->middleware('role:teacher')->name('classes.store');
    Route::post('/classes/join', [EnrollmentController::class, 'join'])->middleware('role:student')->name('classes.join');
    Route::get('/classes/{class}', [ClassController::class, 'show'])->name('classes.show');
    Route::get('/classes/{class}/edit', [ClassController::class, 'edit'])->name('classes.edit');
    Route::put('/classes/{class}', [ClassController::class, 'update'])->name('classes.update');
    Route::delete('/classes/{class}', [ClassController::class, 'destroy'])->name('classes.destroy'); // FR-CLS-14
    Route::put('/classes/{class}/location', [ClassController::class, 'updateLocation'])->name('classes.location');
    // FR-CLS-12: weekly schedules, one at a time
    Route::post('/classes/{class}/schedules', [ClassScheduleController::class, 'store'])->name('schedules.store');
    Route::put('/schedules/{schedule}', [ClassScheduleController::class, 'update'])->name('schedules.update');
    Route::delete('/schedules/{schedule}', [ClassScheduleController::class, 'destroy'])->name('schedules.destroy');
    Route::get('/classes/{class}/students', [EnrollmentController::class, 'index'])->name('classes.students');
    Route::get('/classes/{class}/students/{student}/attendance', [EnrollmentController::class, 'attendance'])->name('classes.student-attendance'); // FR-CLS-15
    Route::post('/classes/{class}/enrollments/approve-all', [EnrollmentController::class, 'approveAll'])->name('enrollments.approve-all'); // FR-CLS-13
    Route::post('/enrollments/{enrollment}/approve', [EnrollmentController::class, 'approve'])->name('enrollments.approve');
    Route::post('/enrollments/{enrollment}/reject', [EnrollmentController::class, 'reject'])->name('enrollments.reject');
    Route::post('/enrollments/{enrollment}/remove', [EnrollmentController::class, 'remove'])->name('enrollments.remove');

    // Attendance (section 5)
    Route::get('/classes/{class}/sessions', [AttendanceSessionController::class, 'index'])->name('sessions.index');
    Route::post('/classes/{class}/sessions', [AttendanceSessionController::class, 'store'])->name('sessions.store');
    // Archived sessions (FR-ATT-16) can still be viewed and restored; every other action needs an active one.
    Route::get('/sessions/{attendanceSession}', [AttendanceSessionController::class, 'show'])->withTrashed()->name('sessions.show');
    Route::get('/sessions/{attendanceSession}/edit', [AttendanceSessionController::class, 'edit'])->name('sessions.edit');
    Route::put('/sessions/{attendanceSession}', [AttendanceSessionController::class, 'update'])->name('sessions.update');
    Route::post('/sessions/{attendanceSession}/archive', [AttendanceSessionController::class, 'archive'])->name('sessions.archive');
    Route::post('/sessions/{attendanceSession}/restore', [AttendanceSessionController::class, 'restore'])->withTrashed()->name('sessions.restore');
    Route::delete('/sessions/{attendanceSession}', [AttendanceSessionController::class, 'destroy'])->withTrashed()->name('sessions.destroy');
    Route::post('/sessions/{attendanceSession}/stages/{stage}/open', [AttendanceSessionController::class, 'open'])
        ->whereIn('stage', ['time_in', 'time_out'])->name('sessions.open');
    Route::post('/sessions/{attendanceSession}/stages/{stage}/close', [AttendanceSessionController::class, 'close'])
        ->whereIn('stage', ['time_in', 'time_out'])->name('sessions.close');
    Route::get('/sessions/{attendanceSession}/live', [AttendanceSessionController::class, 'live'])->name('sessions.live');
    Route::get('/sessions/{attendanceSession}/live/state', [AttendanceSessionController::class, 'liveState'])->name('sessions.live.state');
    Route::post('/sessions/{attendanceSession}/records', [AttendanceSessionController::class, 'manual'])->name('sessions.manual');

    Route::middleware('role:student')->group(function () {
        Route::view('/scanner', 'scan.scanner')->name('scanner'); // in-app camera QR reader
        Route::get('/scan', [ScanController::class, 'show'])->name('scan');
        Route::post('/scan', [ScanController::class, 'store'])->name('scan.store');
        Route::get('/my/attendance', AttendanceHistoryController::class)->name('attendance.history');
    });

    // Announcements and class cancellations (FR-ANN)
    Route::get('/classes/{class}/announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
    Route::post('/classes/{class}/announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
    Route::get('/announcements/{announcement}/edit', [AnnouncementController::class, 'edit'])->name('announcements.edit');
    Route::put('/announcements/{announcement}', [AnnouncementController::class, 'update'])->name('announcements.update');
    Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy'])->name('announcements.destroy');

    // Correction requests (FR-ATT-18)
    Route::post('/records/{attendanceRecord}/corrections', [AttendanceCorrectionController::class, 'store'])->middleware('role:student')->name('corrections.store');
    Route::get('/classes/{class}/corrections', [AttendanceCorrectionController::class, 'index'])->name('corrections.index');
    Route::post('/corrections/{correction}/approve', [AttendanceCorrectionController::class, 'approve'])->name('corrections.approve');
    Route::post('/corrections/{correction}/reject', [AttendanceCorrectionController::class, 'reject'])->name('corrections.reject');

    // Attendance report (section 7)
    Route::get('/classes/{class}/report', [ReportController::class, 'show'])->name('reports.show');
    Route::get('/classes/{class}/report.pdf', [ReportController::class, 'pdf'])->name('reports.pdf');
});

/* ---------------------------------------------------------------------
 | Admin side (FR-ADM): Dashboard, User Management, Activity Log
 * --------------------------------------------------------------------- */
Route::middleware(['auth', 'auth.session', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', AdminDashboardController::class)->name('dashboard');

    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::get('/users/create', [AdminUserController::class, 'create'])->name('users.create');
    Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
    Route::post('/users/bulk', [AdminUserController::class, 'bulk'])->name('users.bulk'); // FR-ADM-20
    Route::get('/users/import', [UserImportController::class, 'create'])->name('users.import');
    Route::post('/users/import', [UserImportController::class, 'store'])->name('users.import.store');
    Route::get('/users/import/template/{type}.{format}', [UserImportController::class, 'template'])
        ->whereIn('type', ['student', 'teacher'])->whereIn('format', ['xlsx', 'csv'])->name('users.import.template');
    Route::get('/users/{user}/edit', [AdminUserController::class, 'edit'])->name('users.edit');
    Route::put('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
    Route::post('/users/{user}/deactivate', [AdminUserController::class, 'deactivate'])->name('users.deactivate');
    Route::post('/users/{user}/activate', [AdminUserController::class, 'activate'])->name('users.activate');

    // FR-LOG-04: read-only. There are deliberately no edit or delete routes.
    Route::get('/activity', ActivityLogController::class)->name('activity.index');
});
