<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Services\ClassStatsService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ClassController extends Controller
{
    /** Class details shown in "Class edited" log entries (FR-LOG-02). */
    private const DETAIL_LABELS = [
        'subject' => 'Subject', 'year_level' => 'Year level', 'section' => 'Section', 'school_term' => 'School term',
        'semester' => 'Semester', 'semester_start' => 'Semester start', 'semester_end' => 'Semester end',
    ];

    public function create()
    {
        return view('classes.create');
    }

    /** FR-CLS-01/02: the creator becomes the class's teacher. */
    public function store(Request $request)
    {
        $class = $request->user()->taughtClasses()->create($this->validated($request));
        Audit::log(Audit::CLASS_CREATED, 'Created class '.Audit::cls($class).' ('.$class->school_term.', '.$class->semester.' sem) · code '.$class->code, $class);

        return redirect()->route('classes.show', $class)->with('status', 'Class created. Share the code '.$class->code.' with your students.');
    }

    /** Teacher overview, or the student's class page (their own attendance only). */
    public function show(Request $request, SchoolClass $class, ClassStatsService $stats)
    {
        $this->authorize('view', $class);
        $user = $request->user();

        if ($user->teaches($class)) {
            $class->load('schedules');

            return view('classes.show', [
                'class' => $class,
                'pendingCount' => $class->enrollments()->where('status', Enrollment::PENDING)->count(),
                'studentCount' => $class->enrollments()->where('status', Enrollment::APPROVED)->count(),
                'recentSessions' => $class->attendanceSessions()->latest('date')->latest()->take(5)->get(),
            ]);
        }

        // Scoped to this student on the server (FR-ACL-03).
        $summary = $stats->termReport($class, collect([$user]));

        return view('classes.student', [
            'class' => $class->load('schedules', 'teacher'),
            'mine' => $summary['rows']->first(),
            'held' => $summary['held'],
            'announcements' => $class->announcements()->with('author')->take(20)->get(), // FR-ANN-03
        ]);
    }

    public function edit(SchoolClass $class)
    {
        $this->authorize('update', $class);

        return view('classes.edit', ['class' => $class->load('schedules')]);
    }

    public function update(Request $request, SchoolClass $class)
    {
        $this->authorize('update', $class);
        $old = $class->only(array_keys(self::DETAIL_LABELS));
        $class->update($this->validated($request));
        if ($changes = Audit::diff($old, $class, self::DETAIL_LABELS)) {
            Audit::log(Audit::CLASS_UPDATED, Audit::cls($class).': '.$changes, $class);
        }

        return redirect()->route('classes.edit', $class)->with('status', 'Class details saved.');
    }

    /**
     * FR-CLS-03: the classroom location (building, floor and room). Weekly schedules are added,
     * edited and removed one at a time in ClassScheduleController (FR-CLS-12).
     */
    public function updateLocation(Request $request, SchoolClass $class)
    {
        $this->authorize('update', $class);

        $data = $request->validateWithBag('location', [
            'building' => ['nullable', 'string', 'max:100'],
            'floor' => ['nullable', 'string', 'max:50'],
            'room' => ['nullable', 'string', 'max:100'],
        ]);

        $old = $class->only(['building', 'floor', 'room']);
        $class->update([
            'building' => filled($data['building'] ?? null) ? trim($data['building']) : null,
            'floor' => filled($data['floor'] ?? null) ? trim($data['floor']) : null,
            'room' => filled($data['room'] ?? null) ? trim($data['room']) : null,
        ]);

        if ($changes = Audit::diff($old, $class, ['building' => 'Building', 'floor' => 'Floor', 'room' => 'Room'])) {
            Audit::log(Audit::CLASS_UPDATED, Audit::cls($class).' classroom location: '.$changes, $class);
        }

        return redirect()->route('classes.edit', $class)->with('status', 'Classroom location saved.');
    }

    /**
     * FR-CLS-14: delete a class for good, with its students list, schedules, sessions, attendance,
     * corrections and announcements (database cascade). A class that has students or sessions
     * also needs its class code typed in, so a real class isn't deleted by one click.
     */
    public function destroy(Request $request, SchoolClass $class)
    {
        $this->authorize('update', $class);

        $students = $class->enrollments()->count();
        $sessions = $class->attendanceSessions()->withTrashed()->count();

        if (($students || $sessions) && Str::upper(trim((string) $request->input('confirm_code'))) !== $class->code) {
            return back()->withErrors(['confirm' => Audit::cls($class).' wasn\'t deleted: type its class code '.$class->code
                .' to confirm (it has '.$students.' '.Str::plural('student', $students).' and '.$sessions.' '.Str::plural('session', $sessions).').']);
        }

        $label = Audit::cls($class).' ('.$class->school_term.', '.$class->semester.' sem, code '.$class->code.')';
        DB::transaction(fn () => $class->delete());

        Audit::log(Audit::CLASS_DELETED, 'Deleted class '.$label.' with '.$students.' '.Str::plural('student', $students)
            .' and '.$sessions.' '.Str::plural('session', $sessions), null, ['class_id' => $class->id]);

        return redirect()->route('dashboard')->with('status', Audit::cls($class).' was deleted.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'subject' => ['required', 'string', 'max:100'],
            'year_level' => ['required', 'string', 'max:50'],
            'section' => ['required', 'string', 'max:50'],
            'school_term' => ['required', 'string', 'max:20', 'regex:/^\d{4}\s*[-–]\s*\d{4}$/'],
            'semester' => ['required', Rule::in(SchoolClass::SEMESTERS)],
            'semester_start' => ['required', 'date'],
            'semester_end' => ['required', 'date', 'after:semester_start'],
        ], [
            'school_term.regex' => 'Use the form 2026-2027.',
        ]);
    }
}
