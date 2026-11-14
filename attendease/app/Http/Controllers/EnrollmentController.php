<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Notifications\JoinRequestDecided;
use App\Notifications\JoinRequestReceived;
use App\Notifications\RemovedFromClass;
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
    public function index(Request $request, SchoolClass $class)
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
            ->sortBy(fn ($e) => $e->student->name, SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        // Sections that actually occur among this class's students, for the dropdown.
        // "B" and "b" are one section; show the capitalised spelling.
        $sections = $all->map(fn ($e) => trim((string) $e->student->section))
            ->filter()
            ->groupBy(fn ($s) => Str::lower($s))
            ->map(fn ($spellings) => $spellings->unique()->sort()->first())
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();
        $hasNoSection = $all->contains(fn ($e) => blank($e->student->section));

        $needle = Str::lower($search);
        $filtered = $all->filter(function ($e) use ($needle, $section) {
            $s = $e->student;

            if ($section === self::NO_SECTION && filled($s->section)) {
                return false;
            }
            if ($section !== '' && $section !== self::NO_SECTION && Str::lower(trim((string) $s->section)) !== Str::lower($section)) {
                return false;
            }

            return $needle === ''
                || Str::contains(Str::lower($s->name), $needle)
                || Str::contains(Str::lower((string) $s->id_number), $needle)
                || Str::contains(Str::lower($s->email), $needle);
        });

        return view('classes.students', [
            'class' => $class,
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

        $enrollment->update(['status' => $status, 'decided_at' => now()]);
        $enrollment->load('schoolClass', 'student');

        // FR-CLS-07
        $enrollment->student->notify($status === Enrollment::REMOVED
            ? new RemovedFromClass($enrollment)
            : new JoinRequestDecided($enrollment));

        return back()->with('status', $enrollment->student->name.' '.$verb.'.');
    }
}
