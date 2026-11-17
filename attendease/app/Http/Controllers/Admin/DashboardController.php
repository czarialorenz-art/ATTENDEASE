<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AttendanceRecord;
use App\Models\ClassSchedule;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FR-ADM-02..05: totals, and the classes scheduled on a chosen day with how many students scanned in.
 * Every number comes from grouped queries, never one query per row.
 */
class DashboardController extends Controller
{
    public const RECORDED = 'recorded';
    public const NO_ATTENDANCE = 'no_attendance';
    public const UPCOMING = 'upcoming';
    public const CANCELLED = 'cancelled';

    public function __invoke(Request $request)
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $request->filled('date') ? Carbon::createFromFormat('Y-m-d', $request->input('date'))->startOfDay() : today();

        $rows = $this->classRows($date);

        return view('admin.dashboard', [
            'totals' => $this->totals(),
            'date' => $date,
            'rows' => $rows,
            'missing' => $rows->where('status', self::NO_ATTENDANCE)->values(),
        ]);
    }

    /** FR-ADM-02: active accounts per role; subjects and sections are distinct values across classes. */
    private function totals(): array
    {
        $byRole = User::query()
            ->whereNull('deactivated_at')
            ->selectRaw('role, count(*) as n')
            ->groupBy('role')
            ->pluck('n', 'role');

        $distinct = SchoolClass::query()
            ->selectRaw('count(distinct lower(trim(subject))) as subjects, count(distinct lower(trim(section))) as sections')
            ->first();

        return [
            'students' => (int) ($byRole[User::STUDENT] ?? 0),
            'teachers' => (int) ($byRole[User::TEACHER] ?? 0),
            'subjects' => (int) $distinct->subjects,
            'sections' => (int) $distinct->sections,
        ];
    }

    /**
     * FR-ADM-03/04: one row per schedule slot on that weekday, for classes whose semester includes the date.
     * Queries: schedules (+ classes, teachers, enrolled counts), attendance per class, cancellations.
     */
    private function classRows(Carbon $date)
    {
        $day = $date->toDateString();

        $schedules = ClassSchedule::query()
            ->where('day_of_week', $date->dayOfWeek)
            ->whereHas('schoolClass', fn ($q) => $q->whereDate('semester_start', '<=', $day)->whereDate('semester_end', '>=', $day))
            ->with(['schoolClass' => fn ($q) => $q
                ->with('teacher:id,name')
                ->withCount(['enrollments as enrolled_count' => fn ($e) => $e->where('status', Enrollment::APPROVED)])])
            ->orderBy('start_time')
            ->get();

        $classIds = $schedules->pluck('class_id')->unique()->values();

        // Records and distinct students who scanned in, per class, from that day's (non-archived) sessions.
        $attendance = AttendanceRecord::query()
            ->join('attendance_sessions as s', 's.id', '=', 'attendance_records.attendance_session_id')
            ->whereNull('s.archived_at')
            ->whereDate('s.date', $day)
            ->whereIn('s.class_id', $classIds)
            ->groupBy('s.class_id')
            ->select('s.class_id')
            ->selectRaw('count(*) as records')
            ->selectRaw('count(distinct case when attendance_records.time_in_at is not null then attendance_records.student_id end) as scanned_in')
            ->get()
            ->keyBy('class_id');

        // A class the teacher announced as cancelled that day isn't chased as "No attendance".
        $cancelled = Announcement::query()
            ->where('type', Announcement::CANCELLATION)
            ->whereDate('cancelled_date', $day)
            ->whereIn('class_id', $classIds)
            ->distinct()
            ->pluck('class_id')
            ->flip();

        $now = now();

        return $schedules->map(function (ClassSchedule $slot) use ($date, $attendance, $cancelled, $now) {
            $class = $slot->schoolClass;
            $stats = $attendance->get($class->id);
            $starts = $date->copy()->setTimeFromTimeString($slot->start_time);
            $ends = $date->copy()->setTimeFromTimeString($slot->end_time);

            $status = match (true) {
                $stats && $stats->records > 0 => self::RECORDED,
                $cancelled->has($class->id) => self::CANCELLED,
                $now->gte($starts) => self::NO_ATTENDANCE,
                default => self::UPCOMING,
            };

            return [
                'class' => $class,
                'subject' => $class->subject,
                'section' => $class->section,
                'teacher' => $class->teacher?->name ?? '—',
                'room' => $class->locationLabel() ?? ($slot->room ?: '—'),
                'time' => $starts->format('g:i A').' – '.$ends->format('g:i A'),
                'scanned_in' => (int) ($stats->scanned_in ?? 0),
                'enrolled' => (int) $class->enrolled_count,
                'status' => $status,
            ];
        });
    }
}
