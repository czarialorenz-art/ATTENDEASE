<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Services\ClassStatsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ClassController extends Controller
{
    public function create()
    {
        return view('classes.create');
    }

    /** FR-CLS-01/02: the creator becomes the class's teacher. */
    public function store(Request $request)
    {
        $class = $request->user()->taughtClasses()->create($this->validated($request));

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
        $class->update($this->validated($request));

        return redirect()->route('classes.edit', $class)->with('status', 'Class details saved.');
    }

    /** FR-CLS-03: weekly schedule rows and classroom location. */
    public function updateSchedule(Request $request, SchoolClass $class)
    {
        $this->authorize('update', $class);

        $rows = collect($request->input('schedules', []))
            ->filter(fn ($r) => filled($r['day_of_week'] ?? null) || filled($r['start_time'] ?? null) || filled($r['end_time'] ?? null))
            ->map(fn ($r) => array_intersect_key($r, array_flip(['day_of_week', 'start_time', 'end_time'])))
            ->values()
            ->all();
        $request->merge(['schedules' => $rows]);

        $data = $request->validate([
            'schedules' => ['array'],
            'schedules.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'schedules.*.start_time' => ['required', 'date_format:H:i'],
            'schedules.*.end_time' => ['required', 'date_format:H:i', 'after:schedules.*.start_time'],
            // Classroom location: building, floor and room name.
            'building' => ['nullable', 'string', 'max:100'],
            'floor' => ['nullable', 'string', 'max:50'],
            'room' => ['nullable', 'string', 'max:100'],
        ], [
            'schedules.*.end_time.after' => 'Each end time must be after its start time.',
        ]);

        DB::transaction(function () use ($class, $data) {
            $class->schedules()->delete();
            foreach ($data['schedules'] ?? [] as $row) {
                $class->schedules()->create($row); // rooms now live on the class, not on each row
            }
            $class->update([
                'building' => filled($data['building'] ?? null) ? trim($data['building']) : null,
                'floor' => filled($data['floor'] ?? null) ? trim($data['floor']) : null,
                'room' => filled($data['room'] ?? null) ? trim($data['room']) : null,
                // Map coordinates were removed from the form, so the distance (TOO_FAR) check is off.
                'latitude' => null,
                'longitude' => null,
                'radius_m' => 100,
            ]);
        });

        return redirect()->route('classes.edit', $class)->with('status', 'Schedule and location saved.');
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
