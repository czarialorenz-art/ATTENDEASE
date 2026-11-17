<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** FR-CLS-08 plus the "today" list from section 10. */
class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return redirect()->route('admin.dashboard');
        }

        // FR-ACL-03: a Teacher sees the classes they teach, a Student the classes they're in.
        $teaching = $user->taughtClasses()
            ->when(! $user->isTeacher(), fn ($q) => $q->whereRaw('1 = 0'))
            ->withCount(['enrollments as pending_count' => fn ($q) => $q->where('status', Enrollment::PENDING)])
            ->withCount(['enrollments as student_count' => fn ($q) => $q->where('status', Enrollment::APPROVED)])
            ->orderByDesc('created_at')
            ->get()
            ->each(fn ($c) => $c->setAttribute('corrections_pending', $c->pendingCorrectionsCount())); // FR-ATT-18

        $enrolled = $user->enrollments()
            ->when(! $user->isStudent(), fn ($q) => $q->whereRaw('1 = 0'))
            ->whereIn('status', [Enrollment::APPROVED, Enrollment::PENDING])
            ->with('schoolClass.teacher')
            ->orderByRaw("case when status = 'approved' then 0 else 1 end")
            ->latest()
            ->get();

        return view('dashboard', [
            'teaching' => $teaching,
            'enrolled' => $enrolled,
            'todaySessions' => $this->todaySessions($user),
            ...$this->studentToday($user),
            'myAnnouncements' => $this->studentAnnouncements($enrolled->where('status', Enrollment::APPROVED)->pluck('class_id')),
        ]);
    }

    /** The teacher's "Today's sessions" card on its own, polled by the dashboard so it stays current. */
    public function today(Request $request)
    {
        return view('dashboard._today', ['todaySessions' => $this->todaySessions($request->user())]);
    }

    /**
     * FR-ANN-03: for the student's dashboard, upcoming cancellations first (soonest first),
     * then anything posted in the last 7 days.
     */
    private function studentAnnouncements(Collection $classIds): Collection
    {
        if ($classIds->isEmpty()) {
            return collect();
        }

        return Announcement::whereIn('class_id', $classIds)
            ->where(fn ($q) => $q
                ->where(fn ($c) => $c->where('type', Announcement::CANCELLATION)->whereDate('cancelled_date', '>=', now()->toDateString()))
                ->orWhere('created_at', '>=', now()->subDays(7)))
            ->with(['schoolClass', 'author'])
            ->latest()->latest('id')
            ->get()
            ->sortBy(fn (Announcement $a) => $a->isUpcomingCancellation() ? '0'.$a->cancelled_date->toDateString() : '1', SORT_STRING)
            ->values()
            ->take(8);
    }

    /** The student's "Today's sessions" card on its own (polled). */
    public function todayStudent(Request $request)
    {
        return view('dashboard._today_student', $this->studentToday($request->user()));
    }

    /**
     * Today's unfinished sessions in the classes this user is an approved student in,
     * with the user's own record for each (FR-ACL-03: only their own).
     *
     * @return array{myTodaySessions: Collection<int, AttendanceSession>, myRecords: Collection}
     */
    private function studentToday(User $user): array
    {
        $classIds = $user->enrollments()->where('status', Enrollment::APPROVED)->pluck('class_id');

        $sessions = AttendanceSession::whereIn('class_id', $classIds)
            ->whereDate('date', now()->toDateString())
            ->whereNull('time_out_closed_at')
            ->with('schoolClass.teacher')
            ->orderBy('created_at')
            ->get()
            ->sortBy(fn (AttendanceSession $s) => $s->openStage() ? 0 : 1, SORT_NUMERIC)
            ->values();

        $records = AttendanceRecord::where('student_id', $user->id)
            ->whereIn('attendance_session_id', $sessions->pluck('id'))
            ->get()
            ->keyBy('attendance_session_id');

        return ['myTodaySessions' => $sessions, 'myRecords' => $records];
    }

    /**
     * Today's sessions in the classes this user teaches that aren't finished yet:
     * ongoing (a stage open) first, then not started or between stages. Completed sessions
     * (Time Out closed) drop off. Archived sessions are excluded automatically.
     *
     * @return Collection<int, AttendanceSession>
     */
    private function todaySessions(User $user): Collection
    {
        return AttendanceSession::whereIn('class_id', $user->taughtClasses()->select('id'))
            ->whereDate('date', now()->toDateString())
            ->whereNull('time_out_closed_at')
            ->with('schoolClass')
            ->orderBy('created_at')
            ->get()
            ->sortBy(fn (AttendanceSession $s) => $s->openStage() ? 0 : 1, SORT_NUMERIC)
            ->values();
    }
}
