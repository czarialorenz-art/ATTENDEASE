<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\SchoolClass;
use Illuminate\Support\Collection;

/**
 * FR-RPT-01: attendance summary per student, with an attendance-sheet grid (FR-RPT-04).
 *
 * A session counts as held once its Time In stage has been opened. A held session with no
 * record for a student counts as an absence.
 */
class ClassStatsService
{
    public const PRESENT = 'P';
    public const LATE = 'L';
    public const ABSENT = 'A';

    /**
     * Sessions held within a date range (the semester by default).
     *
     * @param  array{0: string, 1: string}|null  $range  inclusive Y-m-d dates
     * @return array{rows: Collection, held: int, sessions: Collection<int, AttendanceSession>}
     *   Each row: student, present, late, absent, rate, and marks [session_id => P|L|A].
     */
    public function termReport(SchoolClass $class, Collection $students, ?array $range = null): array
    {
        $range ??= [$class->semester_start->toDateString(), $class->semester_end->toDateString()];

        $sessions = $class->attendanceSessions()
            ->whereNotNull('time_in_opened_at')
            ->whereDate('date', '>=', $range[0])
            ->whereDate('date', '<=', $range[1])
            ->orderBy('date')->orderBy('time_in_opened_at')->orderBy('id')
            ->get(['id', 'date', 'title', 'time_in_opened_at']);

        $records = AttendanceRecord::whereIn('attendance_session_id', $sessions->pluck('id'))
            ->whereIn('student_id', $students->pluck('id'))
            ->get(['attendance_session_id', 'student_id', 'status'])
            ->groupBy('student_id');

        $held = $sessions->count();

        $rows = $students->map(function ($student) use ($sessions, $records, $held) {
            $mine = ($records->get($student->id) ?? collect())->keyBy('attendance_session_id');
            $marks = [];

            foreach ($sessions as $session) {
                $marks[$session->id] = match ($mine->get($session->id)?->status) {
                    AttendanceRecord::PRESENT => self::PRESENT,
                    AttendanceRecord::LATE => self::LATE,
                    default => self::ABSENT, // absent, or no record for a held session
                };
            }

            $present = count(array_keys($marks, self::PRESENT, true));
            $late = count(array_keys($marks, self::LATE, true));

            return [
                'student' => $student,
                'present' => $present,
                'late' => $late,
                'absent' => $held - $present - $late,
                'rate' => $held > 0 ? round(($present + $late) / $held * 100, 1) : null,
                'marks' => $marks,
            ];
        })->values();

        return ['rows' => $rows, 'held' => $held, 'sessions' => $sessions];
    }
}
