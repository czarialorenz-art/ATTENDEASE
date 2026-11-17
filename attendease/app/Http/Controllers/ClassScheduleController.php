<?php

namespace App\Http\Controllers;

use App\Models\ClassSchedule;
use App\Models\SchoolClass;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * FR-CLS-12: the teacher adds, edits and removes a class's weekly schedules one at a time.
 * A schedule may not overlap another schedule of the same class, or a class the same teacher
 * teaches in an overlapping semester.
 */
class ClassScheduleController extends Controller
{
    public function store(Request $request, SchoolClass $class)
    {
        $this->authorize('update', $class);

        $data = $this->validated($request, $class, null, 'addSchedule');
        $schedule = $class->schedules()->create($data);

        return redirect()->route('classes.edit', $class)->with('status', 'Schedule added: '.$this->label($schedule).'.');
    }

    public function update(Request $request, ClassSchedule $schedule)
    {
        $class = $schedule->schoolClass;
        $this->authorize('update', $class);

        $schedule->update($this->validated($request, $class, $schedule, 'schedule'.$schedule->id));

        return redirect()->route('classes.edit', $class)->with('status', 'Schedule updated: '.$this->label($schedule).'.');
    }

    public function destroy(ClassSchedule $schedule)
    {
        $class = $schedule->schoolClass;
        $this->authorize('update', $class);

        $label = $this->label($schedule);
        $schedule->delete();

        return redirect()->route('classes.edit', $class)->with('status', 'Schedule removed: '.$label.'.');
    }

    /** "Monday 8:00 AM – 9:30 AM" */
    private function label(ClassSchedule $schedule): string
    {
        return $schedule->dayName().' '.$schedule->timeRange();
    }

    /** Validates one schedule (errors go to their own bag, so the right form shows them). */
    private function validated(Request $request, SchoolClass $class, ?ClassSchedule $ignore, string $bag): array
    {
        $data = $request->validateWithBag($bag, [
            'day_of_week' => ['required', 'integer', 'between:0,6'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ], [
            'day_of_week.required' => 'Choose a day.',
            'end_time.after' => 'The end time must be after the start time.',
        ], [
            'day_of_week' => 'day', 'start_time' => 'start time', 'end_time' => 'end time',
        ]);
        $data['day_of_week'] = (int) $data['day_of_week'];

        if ($clash = $this->clash($class, $data, $ignore)) {
            throw ValidationException::withMessages(['start_time' => $clash])->errorBag($bag);
        }

        // Stored the same way every time ("08:00:00"), like MySQL's TIME column returns it.
        $data['start_time'] .= ':00';
        $data['end_time'] .= ':00';

        return $data;
    }

    /**
     * The first schedule this one would overlap, as a message: another schedule of this class, or
     * one of the teacher's other classes whose semester overlaps this class's semester.
     */
    private function clash(SchoolClass $class, array $data, ?ClassSchedule $ignore): ?string
    {
        // Times are compared as "HH:MM" (stored values may or may not carry seconds).
        $hm = fn ($t) => substr((string) $t, 0, 5);

        $other = ClassSchedule::query()
            ->with('schoolClass')
            ->where('day_of_week', $data['day_of_week'])
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id))
            ->whereHas('schoolClass', fn ($q) => $q
                ->where('teacher_id', $class->teacher_id)
                ->whereDate('semester_start', '<=', $class->semester_end)
                ->whereDate('semester_end', '>=', $class->semester_start))
            ->get()
            // Overlap: starts before this one ends and ends after it starts (back-to-back is fine).
            ->filter(fn (ClassSchedule $s) => $hm($s->start_time) < $data['end_time'] && $hm($s->end_time) > $data['start_time'])
            ->sortBy(fn (ClassSchedule $s) => [(int) $s->class_id === (int) $class->id ? 0 : 1, $hm($s->start_time)])
            ->first();
        if (! $other) {
            return null;
        }

        return (int) $other->class_id === (int) $class->id
            ? 'This overlaps this class\'s '.$this->label($other).' schedule.'
            : 'This overlaps your class '.$other->schoolClass->subject.' · '.$other->schoolClass->section.' ('.$this->label($other).').';
    }
}
