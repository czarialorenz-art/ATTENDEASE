<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use Illuminate\Http\Request;

/** FR-ATT-14 / FR-ACL-03: the student's own records, in approved classes only. No flag details. */
class AttendanceHistoryController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        $classes = $user->approvedClasses()->orderBy('subject')->get();

        $request->validate([
            'class_id' => ['nullable', 'integer'],
            'term' => ['nullable', 'string', 'max:20'],
        ]);

        $classIds = $classes
            ->when($request->filled('class_id'), fn ($c) => $c->where('id', (int) $request->input('class_id')))
            ->when($request->filled('term'), fn ($c) => $c->where('school_term', $request->input('term')))
            ->pluck('id');

        $records = AttendanceRecord::where('student_id', $user->id)
            ->whereHas('attendanceSession', fn ($q) => $q->whereIn('class_id', $classIds))
            ->with(['attendanceSession.schoolClass', 'latestCorrection']) // FR-ATT-18
            ->get()
            ->sortByDesc(fn ($r) => $r->attendanceSession->date->toDateString().$r->attendanceSession->id)
            ->values();

        return view('attendance.history', [
            'classes' => $classes,
            'terms' => $classes->pluck('school_term')->unique()->sort()->values(),
            'records' => $records,
        ]);
    }
}
