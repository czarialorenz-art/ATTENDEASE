<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\ScanLog;
use App\Models\SchoolClass;
use App\Models\User;
use App\Notifications\AbsenceAlert;
use App\Notifications\TimeInOpened;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class AttendanceService
{
    /* ------------------------------------------------------------------
     | Stages (FR-ATT-02, FR-ATT-03, FR-ATT-13)
     * ------------------------------------------------------------------ */

    /** Returns an error message, or null on success. */
    public function openStage(AttendanceSession $session, string $stage): ?string
    {
        if (! $session->isToday()) {
            return 'A stage can only be opened on the session date ('.$session->date->format('M j, Y').').';
        }

        if ($open = $session->openStage()) {
            return $open === $stage
                ? AttendanceSession::stageLabel($stage).' is already open.'
                : 'Close '.AttendanceSession::stageLabel($open).' first. Only one stage can be open at a time.';
        }

        $firstTimeIn = false;

        if ($stage === AttendanceSession::TIME_IN) {
            if ($session->time_out_opened_at) {
                return 'Time In cannot be reopened after Time Out has started.';
            }
            // Reopening keeps the original opened_at, so the grace period still counts from the first opening.
            $firstTimeIn = $session->time_in_opened_at === null;
            $session->time_in_opened_at ??= now();
            $session->time_in_closed_at = null;
        } else {
            if (! $session->time_in_opened_at) {
                return 'Open and close Time In before starting Time Out.';
            }
            if ($session->time_out_closed_at) {
                return 'Time Out has already closed for this session.';
            }
            $session->time_out_opened_at = now();
        }

        $session->save();

        // FR-ATT-17: tell the class Time In is open (first opening only; a reopen doesn't repeat it).
        if ($firstTimeIn) {
            Notification::send($session->schoolClass->approvedStudents()->get(), new TimeInOpened($session));
        }

        return null;
    }

    public function closeStage(AttendanceSession $session, string $stage): ?string
    {
        if (! $session->isStageOpen($stage)) {
            return AttendanceSession::stageLabel($stage).' is not open.';
        }

        $session->{"{$stage}_closed_at"} = now();
        $session->save();

        if ($stage === AttendanceSession::TIME_OUT) {
            $this->markAbsences($session);
        }

        return null;
    }

    /**
     * FR-ATT-13: every approved student missing a Time In or Time Out is marked Absent and alerted.
     * Records the teacher set by hand are left as the teacher decided.
     */
    public function markAbsences(AttendanceSession $session): int
    {
        $students = $session->schoolClass->approvedStudents()->get();
        $records = $session->records()->get()->keyBy('student_id');
        $marked = 0;

        foreach ($students as $student) {
            $record = $records->get($student->id);

            if ($record && $record->source === AttendanceRecord::SOURCE_MANUAL) {
                continue;
            }

            if ($record && $record->time_in_at && $record->time_out_at) {
                continue;
            }

            if (! $record) {
                $record = new AttendanceRecord([
                    'attendance_session_id' => $session->id,
                    'student_id' => $student->id,
                    'source' => AttendanceRecord::SOURCE_QR,
                ]);
            }

            $record->status = AttendanceRecord::ABSENT;
            $record->save();
            $marked++;

            $student->notify(new AbsenceAlert($session));
        }

        return $marked;
    }

    /* ------------------------------------------------------------------
     | Grace period (FR-ATT-01)
     * ------------------------------------------------------------------ */

    public function updateGrace(AttendanceSession $session, int $minutes): void
    {
        $session->grace_minutes = $minutes;
        $session->save();

        if (! $session->time_in_opened_at) {
            return;
        }

        $session->records()
            ->where('source', AttendanceRecord::SOURCE_QR)
            ->whereNotNull('time_in_at')
            ->whereIn('status', [AttendanceRecord::PRESENT, AttendanceRecord::LATE])
            ->get()
            ->each(function (AttendanceRecord $record) use ($session) {
                $record->update(['status' => $session->statusForTimeIn($record->time_in_at)]);
            });
    }

    /* ------------------------------------------------------------------
     | Manual changes (FR-ATT-12)
     * ------------------------------------------------------------------ */

    /** @param  string  $via  how the change was made, for the activity log (FR-LOG-02) */
    public function setManualStatus(AttendanceSession $session, User $student, string $status, string $via = 'manual edit'): AttendanceRecord
    {
        $record = AttendanceRecord::firstOrNew([
            'attendance_session_id' => $session->id,
            'student_id' => $student->id,
        ]);

        $old = $record->exists ? $record->status : null;
        $wasAbsent = $old === AttendanceRecord::ABSENT;

        $record->status = $status;
        $record->source = AttendanceRecord::SOURCE_MANUAL;
        $record->save();

        if ($old !== $status) {
            $this->logStatusChange($session, $student, $record, $old, $status, $via);
        }

        if ($status === AttendanceRecord::ABSENT && ! $wasAbsent) {
            $student->notify(new AbsenceAlert($session));
        }

        return $record;
    }

    /* ------------------------------------------------------------------
     | Scanning (FR-ATT-08, FR-ATT-09, FR-ATT-10)
     * ------------------------------------------------------------------ */

    /**
     * Records a scan, with its time, IP and device in the scan log.
     *
     * @param  array{device_id?: ?string, ip?: ?string}  $meta
     * @return array{record: AttendanceRecord, already: ?\Carbon\CarbonInterface, log: ?ScanLog}
     */
    public function recordScan(AttendanceSession $session, string $stage, User $student, array $meta): array
    {
        $now = now();
        $column = "{$stage}_at";

        try {
            [$record, $already] = DB::transaction(function () use ($session, $stage, $student, $now, $column) {
                $record = AttendanceRecord::where('attendance_session_id', $session->id)
                    ->where('student_id', $student->id)
                    ->lockForUpdate()
                    ->first();

                if ($record && $record->{$column}) {
                    return [$record, $record->{$column}];
                }

                $record ??= new AttendanceRecord([
                    'attendance_session_id' => $session->id,
                    'student_id' => $student->id,
                    'source' => AttendanceRecord::SOURCE_QR,
                    // A Time Out with no Time In stays Absent; FR-ATT-13 confirms it at close.
                    'status' => AttendanceRecord::ABSENT,
                ]);

                $record->{$column} = $now;

                if ($stage === AttendanceSession::TIME_IN && $record->source === AttendanceRecord::SOURCE_QR) {
                    $record->status = $session->statusForTimeIn($now);
                }

                $record->save();

                return [$record, null];
            });
        } catch (UniqueConstraintViolationException) {
            // Two requests raced to create the record; the other one won. Treat as a repeat scan.
            $record = AttendanceRecord::where('attendance_session_id', $session->id)
                ->where('student_id', $student->id)->firstOrFail();

            return ['record' => $record, 'already' => $record->{$column} ?? $now, 'log' => null];
        }

        if ($already) {
            return ['record' => $record, 'already' => $already, 'log' => null];
        }

        $log = ScanLog::create([
            'attendance_record_id' => $record->id,
            'stage' => $stage,
            'ip' => $meta['ip'] ?? null,
            'device_id' => $meta['device_id'] ?? null ?: null,
            'scanned_at' => $now,
        ]);

        return ['record' => $record, 'already' => null, 'log' => $log];
    }

    /** FR-LOG-02: e.g. "Juan Dela Cruz (2023-00001) · ITE101 BSIT-1A · Nov 12, 2026: Absent → Present (manual edit)". */
    private function logStatusChange(AttendanceSession $session, User $student, AttendanceRecord $record, ?string $old, string $new, string $via): void
    {
        $class = $session->schoolClass;
        $label = fn (?string $s) => $s === null ? 'No record' : ucfirst($s);

        Audit::log(Audit::ATTENDANCE_EDITED,
            Audit::who($student).' · '.$class->subject.' '.$class->section.' · '.$session->date->format('M j, Y')
                .': '.$label($old).' → '.$label($new).' ('.$via.')',
            $record,
            ['old' => $old, 'new' => $new, 'via' => $via, 'student_id' => $student->id,
                'class_id' => $class->id, 'session_id' => $session->id, 'date' => $session->date->toDateString()],
        );
    }
}
