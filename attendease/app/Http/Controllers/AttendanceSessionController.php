<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\ScanFlag;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\QrTokenService;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AttendanceSessionController extends Controller
{
    public function __construct(private AttendanceService $attendance) {}

    /** Active sessions, or ?archived=1 for archived ones (FR-ATT-16). */
    public function index(Request $request, SchoolClass $class)
    {
        $this->authorize('teach', $class);

        // Backup for hosts without a scheduler: drop this class's sessions archived over a year ago.
        AttendanceSession::onlyTrashed()->where('class_id', $class->id)
            ->where('archived_at', '<=', now()->subDays(AttendanceSession::KEEP_ARCHIVED_DAYS))
            ->forceDelete();

        $archived = $request->boolean('archived');
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $search = trim((string) $request->input('q'));

        $query = $class->attendanceSessions()->withCount('records');
        $query = $archived ? $query->onlyTrashed()->latest('archived_at') : $query->latest('date')->latest();

        if ($search !== '') {
            $query->where(fn ($q) => $this->matchSearch($q, $search));
        }

        return view('sessions.index', [
            'class' => $class,
            'archived' => $archived,
            'search' => $search,
            'sessions' => $query->paginate(20)->withQueryString(),
            'archivedCount' => $class->attendanceSessions()->onlyTrashed()->count(),
        ]);
    }

    /**
     * Session search: the title, or the date typed any common way
     * ("Nov 13", "November 13, 2026", "11/13/2026", "2026-11-13", or "2026-11" for a month).
     */
    private function matchSearch($query, string $search): void
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
        $query->where('title', 'like', "%{$escaped}%");

        // Year-month ("2026-11") or a full ISO date fragment.
        if (preg_match('/^\d{4}(-\d{1,2}(-\d{1,2})?)?$/', $search)) {
            $query->orWhere('date', 'like', "{$escaped}%");
        }

        // A readable date: only when it really looks like one, so plain words like "week" don't match.
        if (preg_match('/\d/', $search)) {
            try {
                $date = \Illuminate\Support\Carbon::parse($search, config('app.timezone'));
                // "Nov 13" with no year: match that day in any year.
                if (! preg_match('/\b\d{4}\b/', $search)) {
                    $query->orWhere(fn ($q) => $q->whereMonth('date', $date->month)->whereDay('date', $date->day));
                } else {
                    $query->orWhereDate('date', $date->toDateString());
                }
            } catch (\Throwable) {
                // not a date: title search only
            }
        }
    }

    public function edit(AttendanceSession $attendanceSession)
    {
        $this->authorize('manage', $attendanceSession);

        return view('sessions.edit', ['session' => $attendanceSession, 'class' => $attendanceSession->schoolClass]);
    }

    /** FR-ATT-16: archive. Hidden everywhere; deleted for good after one year unless restored. */
    public function archive(AttendanceSession $attendanceSession)
    {
        $this->authorize('manage', $attendanceSession);

        if ($open = $attendanceSession->openStage()) {
            return back()->withErrors(['stage' => 'Close '.AttendanceSession::stageLabel($open).' before archiving this session.']);
        }

        $attendanceSession->delete(); // soft delete: sets archived_at

        return redirect()->route('sessions.index', $attendanceSession->class_id)->with('status',
            'Session archived. It will be deleted for good on '.$attendanceSession->deletesOn()->format('M j, Y').' unless you restore it.');
    }

    /** FR-ATT-16: delete an archived session for good now, instead of waiting the year. */
    public function destroy(AttendanceSession $attendanceSession)
    {
        $this->authorize('manage', $attendanceSession);

        // Only archived sessions can be deleted, so a live session can't be lost by one click.
        abort_unless($attendanceSession->isArchived(), 404);

        $classId = $attendanceSession->class_id;
        $label = $attendanceSession->date->format('M j, Y');
        $attendanceSession->forceDelete(); // records, scans and flags go with it (cascade)

        return redirect()->route('sessions.index', [$classId, 'archived' => 1])
            ->with('status', "The {$label} session was deleted for good.");
    }

    public function restore(AttendanceSession $attendanceSession)
    {
        $this->authorize('manage', $attendanceSession);

        $attendanceSession->restore();

        return redirect()->route('sessions.index', $attendanceSession->class_id)->with('status', 'Session restored.');
    }

    /** FR-ATT-01 */
    public function store(Request $request, SchoolClass $class)
    {
        $this->authorize('teach', $class);

        $data = $request->validate([
            'date' => ['required', 'date', $this->withinSemester($class)],
            'title' => ['nullable', 'string', 'max:255'],
            'grace_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
        ]);
        $data['grace_minutes'] ??= 15;

        $session = $class->attendanceSessions()->create($data);

        // FR-ANN-04: warn (don't block) when this date was announced as cancelled.
        $cancelled = $class->cancellationOn($session->date);

        return redirect()->route('sessions.show', $session)->with('status', 'Session created.'
            .($cancelled ? ' Note: you announced that class is cancelled on '.$session->date->format('M j, Y').'.' : ''));
    }

    /**
     * FR-ATT-19: sessions must fall inside the class's semester, or they wouldn't count in
     * semester totals. The form shows a link to fix the semester dates next to this error.
     */
    private function withinSemester(SchoolClass $class): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($class) {
            try {
                $problem = $class->semesterProblem((string) $value);
            } catch (\Throwable) {
                return; // not a date: the "date" rule reports it
            }
            if ($problem) {
                $fail($problem.'; it wouldn\'t count in semester totals. Update the semester dates first.');
            }
        };
    }

    /** FR-ATT-11/12: records, flags and manual edits. */
    public function show(AttendanceSession $attendanceSession)
    {
        $this->authorize('manage', $attendanceSession);
        $session = $attendanceSession->load('schoolClass');

        $records = $session->records()->with(['scanLogs.flags.clearedBy'])->get()->keyBy('student_id');
        $students = $session->schoolClass->approvedStudents()->orderBy('name')->get();

        // Include removed students who still have a record here (FR-CLS-06 keeps their history).
        $extra = User::whereIn('id', $records->keys()->diff($students->pluck('id')))->orderBy('name')->get();

        $flags = ScanFlag::whereHas('scanLog.record', fn ($q) => $q->where('attendance_session_id', $session->id))
            ->with(['scanLog.record.student', 'clearedBy'])
            ->orderByRaw('cleared_at is not null')
            ->latest()
            ->get();

        return view('sessions.show', [
            'session' => $session,
            'class' => $session->schoolClass,
            'students' => $students->concat($extra),
            'records' => $records,
            'flags' => $flags,
            'cancellation' => $session->schoolClass->cancellationOn($session->date), // FR-ANN-04
        ]);
    }

    /**
     * FR-ATT-01 / FR-ATT-16: title and grace period can change at any time.
     * The date can change only before Time In has been opened.
     */
    public function update(Request $request, AttendanceSession $attendanceSession)
    {
        $this->authorize('manage', $attendanceSession);

        $data = $request->validate([
            'date' => ['sometimes', 'required', 'date'],
            'title' => ['nullable', 'string', 'max:255'],
            'grace_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
        ]);

        if (isset($data['date']) && $attendanceSession->hasStarted()
            && $attendanceSession->date->toDateString() !== \Illuminate\Support\Carbon::parse($data['date'])->toDateString()) {
            return back()->withErrors(['date' => "The date can't change after Time In has been opened."])->withInput();
        }

        // FR-ATT-19: a new date must be inside the semester (an unchanged date is left alone).
        if (isset($data['date']) && $attendanceSession->date->toDateString() !== \Illuminate\Support\Carbon::parse($data['date'])->toDateString()
            && ($problem = $attendanceSession->schoolClass->semesterProblem($data['date']))) {
            return back()->withErrors(['date' => $problem."; it wouldn't count in semester totals. Update the semester dates first."])->withInput();
        }

        $attendanceSession->update([
            'title' => $data['title'] ?? null,
            ...(isset($data['date']) && ! $attendanceSession->hasStarted() ? ['date' => $data['date']] : []),
        ]);

        if ((int) $data['grace_minutes'] !== $attendanceSession->grace_minutes) {
            $this->attendance->updateGrace($attendanceSession, (int) $data['grace_minutes']);
        }

        return $request->boolean('from_edit')
            ? redirect()->route('sessions.index', $attendanceSession->class_id)->with('status', 'Session updated.')
            : back()->with('status', 'Session updated.');
    }

    public function open(AttendanceSession $attendanceSession, string $stage)
    {
        $this->authorize('manage', $attendanceSession);

        if ($error = $this->attendance->openStage($attendanceSession, $stage)) {
            return back()->withErrors(['stage' => $error]);
        }

        return redirect()->route('sessions.live', $attendanceSession);
    }

    public function close(AttendanceSession $attendanceSession, string $stage)
    {
        $this->authorize('manage', $attendanceSession);

        if ($error = $this->attendance->closeStage($attendanceSession, $stage)) {
            return back()->withErrors(['stage' => $error]);
        }

        return redirect()->route('sessions.show', $attendanceSession)
            ->with('status', AttendanceSession::stageLabel($stage).' closed.');
    }

    /** FR-ATT-04: full-screen rotating QR. */
    public function live(AttendanceSession $attendanceSession)
    {
        $this->authorize('manage', $attendanceSession);

        return view('sessions.live', [
            'session' => $attendanceSession->load('schoolClass'),
            'state' => $this->state($attendanceSession),
        ]);
    }

    /** Polled every 5 seconds by the live screen. */
    public function liveState(AttendanceSession $attendanceSession)
    {
        $this->authorize('manage', $attendanceSession);

        return response()->json($this->state($attendanceSession->fresh()));
    }

    private function state(AttendanceSession $session): array
    {
        $stage = $session->openStage();
        $qr = null;
        $url = null;

        if ($stage && $session->isToday()) {
            $tokens = app(QrTokenService::class);
            $url = route('scan', ['t' => $tokens->issue($session, $stage)]);
            $qr = (new QRCode(new QROptions(['outputBase64' => true, 'scale' => 10, 'addQuietzone' => true])))->render($url);
        }

        $column = ($stage ?? AttendanceSession::TIME_IN).'_at';

        return [
            'stage' => $stage,
            'stage_label' => $stage ? AttendanceSession::stageLabel($stage) : null,
            'qr' => $qr,
            'url' => $url,
            'rotates_in' => app(QrTokenService::class)->secondsUntilRotation(),
            'count' => $session->records()->whereNotNull($column)->count(),
            'total' => $session->schoolClass->approvedStudents()->count(),
            'flags' => ScanFlag::whereNull('cleared_at')
                ->whereHas('scanLog.record', fn ($q) => $q->where('attendance_session_id', $session->id))->count(),
        ];
    }

    /** FR-ATT-12 */
    public function manual(Request $request, AttendanceSession $attendanceSession)
    {
        $this->authorize('manage', $attendanceSession);
        $class = $attendanceSession->schoolClass;

        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'status' => ['required', Rule::in(AttendanceRecord::STATUSES)],
        ]);

        $student = $class->students()
            ->where('users.id', $data['student_id'])
            ->first();

        abort_unless($student && ($student->pivot->status === 'approved'
            || $attendanceSession->records()->where('student_id', $student->id)->exists()), 422, 'That student is not in this class.');

        $this->attendance->setManualStatus($attendanceSession, $student, $data['status']);

        return back()->with('status', $student->name.' marked '.ucfirst($data['status']).'.');
    }

    /** FR-ATT-11: a cleared flag stays in the log. */
    public function clearFlag(Request $request, ScanFlag $scanFlag)
    {
        $this->authorize('clear', $scanFlag);

        if (! $scanFlag->isCleared()) {
            $scanFlag->update(['cleared_by' => $request->user()->id, 'cleared_at' => now()]);
        }

        return back()->with('status', 'Flag cleared.');
    }
}
