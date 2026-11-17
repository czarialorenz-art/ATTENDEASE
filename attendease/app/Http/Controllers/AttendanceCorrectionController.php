<?php

namespace App\Http\Controllers;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\SchoolClass;
use App\Notifications\CorrectionDecided;
use App\Notifications\CorrectionRequested;
use App\Services\AttendanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * FR-ATT-18: students ask to correct an Absent or Late record; the class's teacher
 * approves (the record changes) or rejects it.
 */
class AttendanceCorrectionController extends Controller
{
    public function __construct(private AttendanceService $attendance) {}

    /** Student: request a correction for one of their records. */
    public function store(Request $request, AttendanceRecord $attendanceRecord)
    {
        $this->authorize('requestCorrection', $attendanceRecord);
        $record = $attendanceRecord;

        $allowed = AttendanceCorrection::ALLOWED[$record->status] ?? [];
        if (! $allowed) {
            throw ValidationException::withMessages(['requested_status' => "Only Absent or Late records can be corrected."])
                ->errorBag('correction'.$record->id);
        }

        $data = $request->validateWithBag('correction'.$record->id, [
            'requested_status' => ['required', Rule::in($allowed)],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'reason.min' => 'Explain what happened in at least 10 characters.',
        ], ['requested_status' => 'correct status']);

        $correction = DB::transaction(function () use ($record, $data) {
            // One open request per record at a time.
            if ($record->corrections()->where('status', AttendanceCorrection::PENDING)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['reason' => 'You already have a pending request for this day.'])
                    ->errorBag('correction'.$record->id);
            }

            return $record->corrections()->create([
                'student_id' => $record->student_id,
                'current_status' => $record->status,
                'requested_status' => $data['requested_status'],
                'reason' => trim($data['reason']),
                'status' => AttendanceCorrection::PENDING,
            ]);
        });

        $session = $record->attendanceSession->load('schoolClass.teacher');
        $session->schoolClass->teacher->notify(new CorrectionRequested($correction->load('student'), $session));

        return back()->with('status', 'Correction request sent. Your teacher will review it.');
    }

    /** Teacher: the class's correction requests, pending first. */
    public function index(Request $request, SchoolClass $class)
    {
        $this->authorize('teach', $class);

        $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'all'])]]);
        $filter = $request->input('status', 'pending');

        $query = AttendanceCorrection::query()
            ->whereHas('record', fn ($q) => $q->whereHas('attendanceSession', fn ($s) => $s->withTrashed()->where('class_id', $class->id)))
            ->with(['student', 'decider', 'record.attendanceSession' => fn ($q) => $q->withTrashed()]);

        $counts = (clone $query)->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        $corrections = $query
            ->when($filter !== 'all', fn ($q) => $q->where('status', $filter))
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->latest('id')
            ->paginate(25)->withQueryString();

        return view('classes.corrections', compact('class', 'corrections', 'counts', 'filter'));
    }

    public function approve(Request $request, AttendanceCorrection $correction)
    {
        return $this->decide($request, $correction, AttendanceCorrection::APPROVED);
    }

    public function reject(Request $request, AttendanceCorrection $correction)
    {
        return $this->decide($request, $correction, AttendanceCorrection::REJECTED);
    }

    private function decide(Request $request, AttendanceCorrection $correction, string $status)
    {
        $this->authorize('decide', $correction);

        if (! $correction->isPending()) {
            return back()->withErrors(['enrollment' => 'This request was already handled.']);
        }

        $data = $request->validateWithBag('decide'.$correction->id, [
            'teacher_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $session = $correction->session();

        DB::transaction(function () use ($correction, $status, $data, $request, $session) {
            if ($status === AttendanceCorrection::APPROVED) {
                // Recorded as the teacher's decision (source = manual), so closing Time Out won't undo it.
                $this->attendance->setManualStatus($session, $correction->student, $correction->requested_status, 'correction request approved');
            }

            $correction->update([
                'status' => $status,
                'teacher_note' => filled($data['teacher_note'] ?? null) ? trim($data['teacher_note']) : null,
                'decided_by' => $request->user()->id,
                'decided_at' => now(),
            ]);
        });

        $correction->student->notify(new CorrectionDecided($correction->fresh(), $session->load('schoolClass')));

        return back()->with('status', $correction->student->name."'s request was ".($status === AttendanceCorrection::APPROVED ? 'approved' : 'rejected').'.');
    }
}
