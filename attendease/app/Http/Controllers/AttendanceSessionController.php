<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\QrTokenService;
use App\Support\Audit;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AttendanceSessionController extends Controller
{
    public function __construct(private AttendanceService $attendance) {}

    /** "ITE101 · BSIT-1A · Nov 17, 2026 (Week 3)" for the activity log. */
    private function label(AttendanceSession $session): string
    {
        return Audit::cls($session->schoolClass).' · '.$session->date->format('M j, Y').($session->title ? ' ('.$session->title.')' : '');
    }

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

        // FR-ATT-20: the New session form is for today (Manila): show today's schedule, and whether
        // a session for today already exists or today is outside the semester.
        $today = $this->today();

        return view('sessions.index', [
            'class' => $class,
            'today' => $today,
            'todaySchedules' => $class->schedules()->where('day_of_week', $today->dayOfWeek)->orderBy('start_time')->get(),
            'todaySession' => $class->attendanceSessions()->whereDate('date', $today->toDateString())->latest()->first(),
            'todayProblem' => $class->semesterProblem($today),
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
        Audit::log(Audit::SESSION_ARCHIVED, 'Archived session '.$this->label($attendanceSession), $attendanceSession);

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
        $logLabel = $this->label($attendanceSession);
        $class = $attendanceSession->schoolClass;
        $attendanceSession->forceDelete(); // records and scans go with it (cascade)
        Audit::log(Audit::SESSION_DELETED, 'Deleted session '.$logLabel.' for good, with its attendance records', $class);

        return redirect()->route('sessions.index', [$classId, 'archived' => 1])
            ->with('status', "The {$label} session was deleted for good.");
    }

    public function restore(AttendanceSession $attendanceSession)
    {
        $this->authorize('manage', $attendanceSession);

        $attendanceSession->restore();
        Audit::log(Audit::SESSION_RESTORED, 'Restored session '.$this->label($attendanceSession), $attendanceSession);

        return redirect()->route('sessions.index', $attendanceSession->class_id)->with('status', 'Session restored.');
    }

    /** FR-ATT-20: "today" for new sessions is the school's day, in Manila. */
    private function today(): \Illuminate\Support\Carbon
    {
        return now('Asia/Manila')->startOfDay();
    }

    /**
     * FR-ATT-01, FR-ATT-20: a new session is always for today (Manila), set here on the server;
     * a date sent with the request is ignored. Teachers fix a date on the Edit page.
     */
    public function store(Request $request, SchoolClass $class)
    {
        $this->authorize('teach', $class);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'grace_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
        ]);
        $data['grace_minutes'] ??= 15;
        $data['date'] = $this->today()->toDateString();

        // FR-ATT-19: still blocked when today is outside the semester (the form links to the fix).
        if ($problem = $class->semesterProblem($data['date'])) {
            return back()->withInput()->withErrors(['date' => $problem.'; it wouldn\'t count in semester totals. Update the semester dates first.']);
        }

        $session = $class->attendanceSessions()->create($data);
        Audit::log(Audit::SESSION_CREATED, 'Created session '.$this->label($session).' · grace '.$session->grace_minutes.' min', $session);

        // FR-ANN-04: warn (don't block) when this date was announced as cancelled.
        $cancelled = $class->cancellationOn($session->date);

        return redirect()->route('sessions.show', $session)->with('status', 'Session created.'
            .($cancelled ? ' Note: you announced that class is cancelled on '.$session->date->format('M j, Y').'.' : ''));
    }

    /** FR-ATT-12: records and manual edits. */
    public function show(AttendanceSession $attendanceSession)
    {
        $this->authorize('manage', $attendanceSession);
        $session = $attendanceSession->load('schoolClass');

        $records = $session->records()->get()->keyBy('student_id');
        // Class lists are by surname: "Cruz, Ana", "Loreno, Maria Teresa A.", ...
        $students = $session->schoolClass->approvedStudents()->get()->sortBy(fn ($u) => $u->surnameSortKey(), SORT_NATURAL)->values();

        // Include removed students who still have a record here (FR-CLS-06 keeps their history).
        $extra = User::whereIn('id', $records->keys()->diff($students->pluck('id')))->get()
            ->sortBy(fn ($u) => $u->surnameSortKey(), SORT_NATURAL)->values();

        return view('sessions.show', [
            'session' => $session,
            'class' => $session->schoolClass,
            'students' => $students->concat($extra),
            'records' => $records,
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

        $old = $attendanceSession->only(['date', 'title', 'grace_minutes']);
        $attendanceSession->update([
            'title' => $data['title'] ?? null,
            ...(isset($data['date']) && ! $attendanceSession->hasStarted() ? ['date' => $data['date']] : []),
        ]);

        if ((int) $data['grace_minutes'] !== $attendanceSession->grace_minutes) {
            $this->attendance->updateGrace($attendanceSession, (int) $data['grace_minutes']);
        }
        $attendanceSession->refresh();
        if ($changes = Audit::diff($old, $attendanceSession, ['date' => 'Date', 'title' => 'Title', 'grace_minutes' => 'Grace (min)'])) {
            Audit::log(Audit::SESSION_UPDATED, 'Session '.$this->label($attendanceSession).': '.$changes, $attendanceSession);
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
        Audit::log(Audit::STAGE_OPENED, 'Opened '.AttendanceSession::stageLabel($stage).' · '.$this->label($attendanceSession), $attendanceSession, ['stage' => $stage]);

        return redirect()->route('sessions.live', $attendanceSession);
    }

    public function close(AttendanceSession $attendanceSession, string $stage)
    {
        $this->authorize('manage', $attendanceSession);

        if ($error = $this->attendance->closeStage($attendanceSession, $stage)) {
            return back()->withErrors(['stage' => $error]);
        }
        Audit::log(Audit::STAGE_CLOSED, 'Closed '.AttendanceSession::stageLabel($stage).' · '.$this->label($attendanceSession), $attendanceSession, ['stage' => $stage]);

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
}
