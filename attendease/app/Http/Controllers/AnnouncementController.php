<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\SchoolClass;
use App\Notifications\AnnouncementPosted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

/**
 * FR-ANN-01..05: the teacher posts announcements and class cancellations to a class.
 */
class AnnouncementController extends Controller
{
    public function index(SchoolClass $class)
    {
        $this->authorize('teach', $class);

        return view('classes.announcements', [
            'class' => $class,
            'announcements' => $class->announcements()->with('author')->paginate(20),
        ]);
    }

    public function store(Request $request, SchoolClass $class)
    {
        $this->authorize('teach', $class);

        $data = $this->validated($request, $class);
        $announcement = $class->announcements()->create([...$data, 'author_id' => $request->user()->id]);
        \App\Support\Audit::log(\App\Support\Audit::ANNOUNCEMENT_POSTED, \App\Support\Audit::cls($class).': '.$this->logLabel($announcement), $announcement);

        // FR-ANN-02: tell the class (bell + email).
        Notification::send($class->approvedStudents()->get(), new AnnouncementPosted($announcement->load('schoolClass')));

        $status = $announcement->isCancellation()
            ? 'Cancellation posted for '.$announcement->cancelled_date->format('M j, Y').'. Your students were notified.'
            : 'Announcement posted. Your students were notified.';

        return redirect()->route('announcements.index', $class)
            ->with('status', $status.$this->sessionWarning($class, $announcement));
    }

    /** "Class cancellation for Nov 20, 2026: "No class"" / ""Quiz on Friday"" */
    private function logLabel(Announcement $a): string
    {
        return ($a->isCancellation() ? 'class cancellation for '.$a->cancelled_date->format('M j, Y').': ' : '').'"'.\Illuminate\Support\Str::limit((string) $a->title, 80).'"';
    }

    public function edit(Announcement $announcement)
    {
        $this->authorize('manage', $announcement);

        return view('classes.announcement-edit', ['announcement' => $announcement, 'class' => $announcement->schoolClass]);
    }

    /** Edits are saved quietly: students aren't notified again. */
    public function update(Request $request, Announcement $announcement)
    {
        $this->authorize('manage', $announcement);
        $class = $announcement->schoolClass;

        $announcement->update($this->validated($request, $class, $announcement));
        \App\Support\Audit::log(\App\Support\Audit::ANNOUNCEMENT_UPDATED, \App\Support\Audit::cls($class).': '.$this->logLabel($announcement), $announcement);

        return redirect()->route('announcements.index', $class)
            ->with('status', 'Announcement updated.'.$this->sessionWarning($class, $announcement));
    }

    public function destroy(Announcement $announcement)
    {
        $this->authorize('manage', $announcement);
        $classId = $announcement->class_id;
        $label = \App\Support\Audit::cls($announcement->schoolClass).': '.$this->logLabel($announcement);
        $class = $announcement->schoolClass;
        $announcement->delete();
        \App\Support\Audit::log(\App\Support\Audit::ANNOUNCEMENT_DELETED, $label, $class);

        return redirect()->route('announcements.index', $classId)->with('status', 'Announcement deleted.');
    }

    private function validated(Request $request, SchoolClass $class, ?Announcement $existing = null): array
    {
        $isCancellation = $request->input('type') === Announcement::CANCELLATION;

        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(Announcement::TYPES))],
            'title' => [$isCancellation ? 'nullable' : 'required', 'string', 'max:120'],
            'body' => [$isCancellation ? 'nullable' : 'required', 'string', 'max:2000'],
            'cancelled_date' => [
                Rule::requiredIf($isCancellation), 'nullable', 'date',
                // A new cancellation can't be in the past; an edit may keep its original date.
                $existing?->cancelled_date ? 'after_or_equal:'.min(now()->toDateString(), $existing->cancelled_date->toDateString()) : 'after_or_equal:today',
                // One cancellation per date.
                function ($attribute, $value, $fail) use ($isCancellation, $class, $existing) {
                    if (! $isCancellation || ! $value) {
                        return;
                    }
                    $other = $class->cancellationOn($value);
                    if ($other && $other->id !== $existing?->id) {
                        $fail('You already cancelled class on that date.');
                    }
                },
            ],
        ], [
            'cancelled_date.required' => 'Choose the date of the class that is cancelled.',
            'cancelled_date.after_or_equal' => 'The cancelled date must be today or later.',
            'body.required' => 'Write the announcement.',
        ], ['cancelled_date' => 'date', 'body' => 'message']);

        if ($isCancellation) {
            $data['title'] = filled($data['title'] ?? null) ? $data['title'] : 'Class cancelled';
        } else {
            $data['cancelled_date'] = null;
        }

        return $data;
    }

    /** If a session already exists on the cancelled date, remind the teacher to archive it. */
    private function sessionWarning(SchoolClass $class, Announcement $announcement): string
    {
        if (! $announcement->isCancellation()) {
            return '';
        }

        $count = $class->attendanceSessions()->whereDate('date', $announcement->cancelled_date->toDateString())->count();

        return $count ? " Note: you have {$count} attendance ".\Illuminate\Support\Str::plural('session', $count)
            .' on that date. Archive '.($count === 1 ? 'it' : 'them').' from the Sessions tab if the class won\'t meet.' : '';
    }
}
