<?php

namespace App\Support;

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

    public const ATTENDANCE_EDITED = 'attendance_edited';
    public const USER_ADDED = 'user_added';
    public const USER_EDITED = 'user_edited';
    public const ROLE_CHANGED = 'role_changed';
    public const USER_DEACTIVATED = 'user_deactivated';
    public const USER_ACTIVATED = 'user_activated';
    public const USERS_IMPORTED = 'users_imported';

    /** Action types for the filter and the "Action" column, in display order. */
    public const ACTIONS = [
        self::ATTENDANCE_EDITED => 'Attendance edited',
        self::USER_ADDED => 'User added',
        self::USER_EDITED => 'User edited',
        self::ROLE_CHANGED => 'Role changed',
        self::USER_DEACTIVATED => 'User deactivated',
        self::USER_ACTIVATED => 'User activated',
        self::USERS_IMPORTED => 'Users imported',
    ];

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
        return self::ACTIONS[$event] ?? ucfirst(str_replace('_', ' ', (string) $event));
    }

    /** "Juan Dela Cruz (2023-00001)" */
    public static function who(User $user): string
    {
        return $user->name.' ('.$user->id_number.')';
    }
}
