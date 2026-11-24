<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\User;
use App\Notifications\JoinRequestDecided;
use App\Notifications\JoinRequestReceived;
use App\Notifications\RemovedFromClass;
use App\Services\ClassStatsService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EnrollmentController extends Controller
{
    /** Filter value for students with no section set. */
    public const NO_SECTION = '__none';

    /**
     * FR-CLS-05: pending requests and the approved roster.
     * FR-CLS-10: filter by the student's section and search by name (or student number / email).
     */
    public function index(Request $request, SchoolClass $class, ClassStatsService $stats)
    {
        $this->authorize('teach', $class);

        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'section' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim((string) $request->input('q'));
        $section = trim((string) $request->input('section'));

        $all = $class->enrollments()
            ->whereIn('status', [Enrollment::PENDING, Enrollment::APPROVED, Enrollment::REMOVED])
            ->with('student')->get()
            ->sortBy(fn ($e) => $e->student->surnameSortKey(), SORT_NATURAL) // by surname
            ->values();

        // Sections that actually occur among this class's students (e.g. "BSIT 4-B", FR-ADM-10), for the dropdown.
        // Spellings that differ only in case are one section.
        $sections = $all->map(fn ($e) => trim((string) $e->student->sectionCode()))
            ->filter()
            ->groupBy(fn ($s) => Str::lower($s))
            ->map(fn ($spellings) => $spellings->unique()->sort()->first())
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();
        $hasNoSection = $all->contains(fn ($e) => blank($e->student->sectionCode()));

        $needle = Str::lower($search);
        $filtered = $all->filter(function ($e) use ($needle, $section) {
            $s = $e->student;

            if ($section === self::NO_SECTION && filled($s->sectionCode())) {
                return false;
            }
            if ($section !== '' && $section !== self::NO_SECTION && Str::lower(trim((string) $s->sectionCode())) !== Str::lower($section)) {
                return false;
            }

            return $needle === ''
                || Str::contains(Str::lower($s->name), $needle)
                || Str::contains(Str::lower((string) $s->id_number), $needle)
                || Str::contains(Str::lower((string) $s->email), $needle);
        });

        // FR-CLS-15: each enrolled student's semester attendance rate, the same numbers as their own class page.
        $rates = $stats->termReport($class, $filtered->where('status', Enrollment::APPROVED)->pluck('student'))['rows']
            ->mapWithKeys(fn ($r) => [$r['student']->id => $r['rate']]);

        return view('classes.students', [
            'class' => $class,
            'rates' => $rates,
            'pending' => $filtered->where('status', Enrollment::PENDING),
            'approved' => $filtered->where('status', Enrollment::APPROVED),
            'removed' => $filtered->where('status', Enrollment::REMOVED),
            'totals' => $all->countBy('status'),
            'sections' => $sections,
            'hasNoSection' => $hasNoSection,
            'search' => $search,
            'section' => $section,
            'filtering' => $search !== '' || $section !== '',
        ]);
    }

    /**
     * FR-CLS-15: "View attendance" from the Students tab: one student's semester summary in this
     * class (the same numbers as the student's own class page) and their records, newest first.
     * The class's teacher only; the student must be, or have been, enrolled.
     */
    public function attendance(SchoolClass $class, User $student, ClassStatsService $stats)
    {
        $this->authorize('teach', $class);

        $enrollment = $class->enrollments()->where('student_id', $student->id)
            ->whereIn('status', [Enrollment::APPROVED, Enrollment::REMOVED])->first();
        abort_unless($enrollment, 404);

        $summary = $stats->termReport($class, collect([$student]));

        $records = AttendanceRecord::where('student_id', $student->id)
            ->whereHas('attendanceSession', fn ($q) => $q->where('class_id', $class->id))
            ->with('attendanceSession')
            ->get()
            ->sortByDesc(fn ($r) => $r->attendanceSession->date->toDateString().sprintf('|%010d', $r->attendance_session_id))
            ->values();

        return view('classes.student-attendance', [
            'class' => $class,
            'student' => $student,
            'removed' => $enrollment->status === Enrollment::REMOVED,
            'mine' => $summary['rows']->first(),
            'held' => $summary['held'],
            'records' => $records,
        ]);
    }

    /** FR-CLS-04 */
    public function join(Request $request)
    {
        $request->merge(['code' => Str::upper(preg_replace('/\s+/', '', (string) $request->input('code')))]);
        $request->validate(['code' => ['required', 'string', 'max:8']], [], ['code' => 'class code']);

        $class = SchoolClass::where('code', $request->input('code'))->first();
        $user = $request->user();

        if (! $class) {
            throw ValidationException::withMessages(['code' => 'No class matches that code. Check it with your teacher.']);
        }

        if ($user->teaches($class)) {
            throw ValidationException::withMessages(['code' => 'You teach this class, so you cannot join it as a student.']);
        }

        $enrollment = Enrollment::firstOrNew(['class_id' => $class->id, 'student_id' => $user->id]);

        if ($enrollment->exists && $enrollment->status === Enrollment::PENDING) {
            throw ValidationException::withMessages(['code' => 'You already asked to join this class. Wait for your teacher to approve it.']);
        }

        if ($enrollment->exists && $enrollment->status === Enrollment::APPROVED) {
            throw ValidationException::withMessages(['code' => 'You are already in this class.']);
        }

        // New, previously rejected, or previously removed: (re)open a pending request.
        $enrollment->fill(['status' => Enrollment::PENDING, 'decided_at' => null])->save();
        Audit::log(Audit::JOIN_REQUESTED, Audit::who($user).' asked to join '.Audit::cls($class).' (code '.$class->code.')', $enrollment);

        $class->teacher->notify(new JoinRequestReceived($enrollment->load('schoolClass', 'student')));

        return redirect()->route('dashboard')->with('status', 'Request sent to join '.$class->label().'. Your teacher will review it.');
    }

    public function approve(Enrollment $enrollment)
    {
        return $this->decide($enrollment, Enrollment::APPROVED, [Enrollment::PENDING], 'approved');
    }

    public function reject(Enrollment $enrollment)
    {
        return $this->decide($enrollment, Enrollment::REJECTED, [Enrollment::PENDING], 'rejected');
    }

    /** FR-CLS-06: past attendance and submissions are kept. */
    public function remove(Enrollment $enrollment)
    {
        return $this->decide($enrollment, Enrollment::REMOVED, [Enrollment::APPROVED], 'removed');
    }

    private function decide(Enrollment $enrollment, string $status, array $from, string $verb)
    {
        $this->authorize('decide', $enrollment);

        if (! in_array($enrollment->status, $from, true)) {
            return back()->withErrors(['enrollment' => 'This request was already handled.']);
        }

        $this->apply($enrollment, $status, $verb);

        return back()->with('status', $enrollment->student->name.' '.$verb.'.');
    }

    /** Saves the decision, logs it (FR-LOG-02) and tells the student (FR-CLS-07). */
    private function apply(Enrollment $enrollment, string $status, string $verb): void
    {
        $enrollment->update(['status' => $status, 'decided_at' => now()]);
        $enrollment->load('schoolClass', 'student');
        Audit::log(
            [Enrollment::APPROVED => Audit::JOIN_APPROVED, Enrollment::REJECTED => Audit::JOIN_REJECTED, Enrollment::REMOVED => Audit::STUDENT_REMOVED][$status],
            Audit::who($enrollment->student).' '.($status === Enrollment::REMOVED ? 'removed from ' : $verb.' to join ').Audit::cls($enrollment->schoolClass),
            $enrollment,
        );

        $enrollment->student->notify($status === Enrollment::REMOVED
            ? new RemovedFromClass($enrollment)
            : new JoinRequestDecided($enrollment));
    }

    /**
     * FR-CLS-13: approve every pending join request on the Students page at once (or the ones
     * the search / section filter shows: their IDs are sent). Requests already handled are skipped.
     */
    public function approveAll(Request $request, SchoolClass $class)
    {
        $this->authorize('teach', $class);

        $data = $request->validate([
            'ids' => ['required', 'array', 'max:1000'],
            'ids.*' => ['integer'],
        ], ['ids.required' => 'There are no join requests to approve.']);

        $pending = $class->enrollments()
            ->whereIn('id', $data['ids'])
            ->where('status', Enrollment::PENDING)
            ->with('student')
            ->get();

        foreach ($pending as $enrollment) {
            $this->apply($enrollment, Enrollment::APPROVED, 'approved');
        }

        $n = $pending->count();

        return back()->with('status', $n
            ? $n.' '.Str::plural('student', $n).' approved.' // FR-CLS-13: each one is notified in apply()
            : 'Those requests were already handled.');
    }
}
