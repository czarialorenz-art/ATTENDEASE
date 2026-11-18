<?php

namespace App\Support;

use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Contracts\Activity;

/**
 * FR-LOG-01: one place that writes the activity log (spatie/laravel-activitylog).
 *
 * Each entry stores the action type in `event`, a ready-to-read sentence in `description`
 * (the "Details" column) and the facts behind it in `properties`, including the role the
 * person had at the time, so the log still reads right after their role changes.
 */
class Audit
{
    public const LOG = 'audit';

    // Admin: accounts
    public const USER_ADDED = 'user_added';
    public const USER_EDITED = 'user_edited';
    public const ROLE_CHANGED = 'role_changed';
    public const USER_DEACTIVATED = 'user_deactivated';
    public const USER_ACTIVATED = 'user_activated';
    public const USERS_IMPORTED = 'users_imported';

    // Attendance
    public const ATTENDANCE_SCANNED = 'attendance_scanned';
    public const ATTENDANCE_EDITED = 'attendance_edited';
    public const CORRECTION_REQUESTED = 'correction_requested';
    public const CORRECTION_APPROVED = 'correction_approved';
    public const CORRECTION_REJECTED = 'correction_rejected';

    // Sessions
    public const SESSION_CREATED = 'session_created';
    public const SESSION_UPDATED = 'session_updated';
    public const STAGE_OPENED = 'stage_opened';
    public const STAGE_CLOSED = 'stage_closed';
    public const SESSION_ARCHIVED = 'session_archived';
    public const SESSION_RESTORED = 'session_restored';
    public const SESSION_DELETED = 'session_deleted';

    // Classes and students
    public const CLASS_CREATED = 'class_created';
    public const CLASS_UPDATED = 'class_updated';
    public const CLASS_DELETED = 'class_deleted';
    public const SCHEDULE_ADDED = 'schedule_added';
    public const SCHEDULE_UPDATED = 'schedule_updated';
    public const SCHEDULE_REMOVED = 'schedule_removed';
    public const JOIN_REQUESTED = 'join_requested';
    public const JOIN_APPROVED = 'join_approved';
    public const JOIN_REJECTED = 'join_rejected';
    public const STUDENT_REMOVED = 'student_removed';

    // Announcements
    public const ANNOUNCEMENT_POSTED = 'announcement_posted';
    public const ANNOUNCEMENT_UPDATED = 'announcement_updated';
    public const ANNOUNCEMENT_DELETED = 'announcement_deleted';

    // Sign-in and account
    public const LOGGED_IN = 'logged_in';
    public const PROFILE_UPDATED = 'profile_updated';
    public const PASSWORD_CHANGED = 'password_changed';

    /**
     * FR-LOG-03: action types for the filter (grouped) and the "Action" column.
     *
     * @var array<string, array<string, string>>
     */
    public const GROUPS = [
        'Attendance' => [
            self::ATTENDANCE_SCANNED => 'Attendance scanned',
            self::ATTENDANCE_EDITED => 'Attendance edited',
            self::CORRECTION_REQUESTED => 'Correction requested',
            self::CORRECTION_APPROVED => 'Correction approved',
            self::CORRECTION_REJECTED => 'Correction rejected',
        ],
        'Sessions' => [
            self::SESSION_CREATED => 'Session created',
            self::SESSION_UPDATED => 'Session edited',
            self::STAGE_OPENED => 'Stage opened',
            self::STAGE_CLOSED => 'Stage closed',
            self::SESSION_ARCHIVED => 'Session archived',
            self::SESSION_RESTORED => 'Session restored',
            self::SESSION_DELETED => 'Session deleted',
        ],
        'Classes & students' => [
            self::CLASS_CREATED => 'Class created',
            self::CLASS_UPDATED => 'Class edited',
            self::CLASS_DELETED => 'Class deleted',
            self::SCHEDULE_ADDED => 'Schedule added',
            self::SCHEDULE_UPDATED => 'Schedule edited',
            self::SCHEDULE_REMOVED => 'Schedule removed',
            self::JOIN_REQUESTED => 'Join requested',
            self::JOIN_APPROVED => 'Join approved',
            self::JOIN_REJECTED => 'Join rejected',
            self::STUDENT_REMOVED => 'Student removed',
        ],
        'Announcements' => [
            self::ANNOUNCEMENT_POSTED => 'Announcement posted',
            self::ANNOUNCEMENT_UPDATED => 'Announcement edited',
            self::ANNOUNCEMENT_DELETED => 'Announcement deleted',
        ],
        'User accounts (admin)' => [
            self::USER_ADDED => 'User added',
            self::USER_EDITED => 'User edited',
            self::ROLE_CHANGED => 'Role changed',
            self::USER_DEACTIVATED => 'User deactivated',
            self::USER_ACTIVATED => 'User activated',
            self::USERS_IMPORTED => 'Users imported',
        ],
        'Sign-in & profile' => [
            self::LOGGED_IN => 'Logged in',
            self::PROFILE_UPDATED => 'Profile updated',
            self::PASSWORD_CHANGED => 'Password changed',
        ],
    ];

    /** @return array<string, string> every action type => label */
    public static function actions(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    public static function log(string $event, string $details, ?Model $subject = null, array $properties = [], ?User $causer = null): ?Activity
    {
        $causer ??= auth()->user();

        $logger = activity(self::LOG)
            ->event($event)
            ->causedBy($causer)
            ->withProperties([...$properties, 'causer_role' => $causer?->role]);

        if ($subject) {
            $logger->performedOn($subject);
        }

        return $logger->log($details);
    }

    public static function actionLabel(?string $event): string
    {
        return self::actions()[$event] ?? ucfirst(str_replace('_', ' ', (string) $event));
    }

    /** "Juan Dela Cruz (2023-00001)" */
    public static function who(User $user): string
    {
        return $user->name.' ('.$user->id_number.')';
    }

    /**
     * "Section: A → B; Room: — → Tesda Lab" for the fields that changed (dates shown as Y-m-d).
     *
     * @param  array<string, mixed>  $old  values before the change
     * @param  array<string, string>  $labels  field => label, in display order
     */
    public static function diff(array $old, Model $model, array $labels): string
    {
        $show = fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : ((string) $v === '' ? '—' : (string) $v);

        return collect($labels)
            ->filter(fn ($label, $field) => $show($old[$field] ?? null) !== $show($model->{$field}))
            ->map(fn ($label, $field) => $label.': '.$show($old[$field] ?? null).' → '.$show($model->{$field}))
            ->implode('; ');
    }

    /** "ITE101 · BSIT-1A" */
    public static function cls(SchoolClass $class): string
    {
        return $class->subject.' · '.$class->section;
    }
}
