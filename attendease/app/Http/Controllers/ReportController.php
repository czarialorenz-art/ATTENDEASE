<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Services\ClassStatsService;
use App\Support\FileName;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * FR-RPT-01..03: attendance summary, teacher only.
 * FR-RPT-07: the period is a day, a week (From–To, up to 7 days), a month (whole month, or a
 * From–To range inside one month) or the whole semester.
 * FR-RPT-05: status filter and search. FR-RPT-06: one student's attendance record.
 */
class ReportController extends Controller
{
    public const PERIODS = [
        'day' => 'Daily',
        'week' => 'Weekly',
        'month' => 'Monthly',
        'semester' => 'Semester',
    ];

    /** FR-RPT-07: how a month is chosen. */
    public const MONTH_MODES = [
        'whole' => 'Whole month',
        'custom' => 'Custom range',
    ];

    /** FR-RPT-05: status filter. "all" keeps every student; the others keep those with at least one such mark. */
    public const STATUSES = [
        'all' => 'All',
        'present' => 'Present',
        'late' => 'Late',
        'absent' => 'Absent',
    ];

    /** Status filter → the grid mark it keeps. */
    public const STATUS_MARKS = [
        'present' => ClassStatsService::PRESENT,
        'late' => ClassStatsService::LATE,
        'absent' => ClassStatsService::ABSENT,
    ];

    /** Date columns per table in the PDF download (portrait A4). */
    public const DATES_PER_PRINTED_TABLE = 8;

    public function __construct(private ClassStatsService $stats) {}

    public function show(Request $request, SchoolClass $class)
    {
        $this->authorize('teach', $class);

        return view('reports.show', $this->data($request, $class));
    }

    public function pdf(Request $request, SchoolClass $class)
    {
        $this->authorize('teach', $class);
        $d = $this->data($request, $class);

        return Pdf::loadView($d['single'] ? 'pdf.student-attendance' : 'pdf.attendance-report', $d)
            ->setPaper('a4', 'portrait')
            ->download($d['fileName'].'.pdf');
    }

    /**
     * FR-RPT-07: resolves the chosen period to an inclusive date range.
     * Older links with only `date` for a week or month still work (the week or month of that day).
     *
     * @return array{period: string, monthMode: string, date: CarbonImmutable, from: CarbonImmutable, to: CarbonImmutable}
     */
    private function range(Request $request, SchoolClass $class): array
    {
        $request->validate([
            'period' => ['nullable', Rule::in(array_keys(self::PERIODS))],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'month' => ['nullable', 'date_format:Y-m'],
            'month_mode' => ['nullable', Rule::in(array_keys(self::MONTH_MODES))],
            'status' => ['nullable', Rule::in(array_keys(self::STATUSES))], // FR-RPT-05
            'q' => ['nullable', 'string', 'max:100'],
            'student' => ['nullable', 'integer'],
        ], [
            'from.date_format' => 'Enter the From date as a date.',
            'to.date_format' => 'Enter the To date as a date.',
            'month.date_format' => 'Choose a month.',
        ], ['q' => 'search']);

        $day = fn (?string $v) => $v ? CarbonImmutable::createFromFormat('Y-m-d', $v)->startOfDay() : null;
        $period = $request->input('period', 'semester');
        $date = $day($request->input('date')) ?? CarbonImmutable::today();
        $from = $day($request->input('from'));
        $to = $day($request->input('to'));
        $monthMode = $request->input('month_mode', 'whole');

        switch ($period) {
            case 'day':
                [$from, $to] = [$date, $date];
                break;

            case 'week': // default: Monday to Sunday of the week of `date` (today)
                $from ??= $to ? $to->subDays(6) : $date->startOfWeek(CarbonImmutable::MONDAY);
                $to ??= $from->addDays(6);
                if ($to->lt($from)) {
                    throw ValidationException::withMessages(['to' => 'The To date can\'t be before the From date.']);
                }
                if ($from->diffInDays($to) > 6) {
                    throw ValidationException::withMessages(['to' => 'A week can be at most 7 days: the To date must be on or before '.$from->addDays(6)->format('M j, Y').'.']);
                }
                break;

            case 'month':
                if ($monthMode === 'custom') {
                    $from ??= $date->startOfMonth();
                    $to ??= $from->endOfMonth()->startOfDay();
                    if ($to->lt($from)) {
                        throw ValidationException::withMessages(['to' => 'The To date can\'t be before the From date.']);
                    }
                    if ($from->format('Y-m') !== $to->format('Y-m')) {
                        throw ValidationException::withMessages(['to' => 'A custom range must be inside one month: pick a To date in '.$from->format('F Y').'.']);
                    }
                } else {
                    $month = $request->filled('month')
                        ? CarbonImmutable::createFromFormat('!Y-m', $request->input('month'))
                        : $date->startOfMonth();
                    [$from, $to] = [$month->startOfMonth(), $month->endOfMonth()->startOfDay()];
                }
                break;

            default:
                $period = 'semester';
                [$from, $to] = [CarbonImmutable::parse($class->semester_start)->startOfDay(), CarbonImmutable::parse($class->semester_end)->startOfDay()];
        }

        // The date shown in the Day picker follows the range.
        $date = $period === 'day' ? $date : $from;

        return compact('period', 'monthMode', 'date', 'from', 'to');
    }

    /** "Nov 24, 2026", "Nov 16–20, 2026", "Nov 30 – Dec 6, 2026", "Dec 28, 2026 – Jan 3, 2027" */
    public static function rangeLabel(CarbonImmutable $from, CarbonImmutable $to): string
    {
        return match (true) {
            $from->isSameDay($to) => $from->format('M j, Y'),
            $from->format('Y-m') === $to->format('Y-m') => $from->format('M j').'–'.$to->format('j, Y'),
            $from->year === $to->year => $from->format('M j').' – '.$to->format('M j, Y'),
            default => $from->format('M j, Y').' – '.$to->format('M j, Y'),
        };
    }

    private function data(Request $request, SchoolClass $class): array
    {
        ['period' => $period, 'monthMode' => $monthMode, 'date' => $date, 'from' => $from, 'to' => $to] = $this->range($request, $class);
        $wholeMonth = $period === 'month' && $monthMode === 'whole';

        // Approved students plus removed ones, whose history is kept for reports (FR-CLS-06).
        $students = $class->students()
            ->wherePivotIn('status', [Enrollment::APPROVED, Enrollment::REMOVED])
            ->get()
            ->sortBy(fn ($u) => $u->surnameSortKey(), SORT_NATURAL) // by surname, like a class record
            ->values();

        $report = $this->stats->termReport($class, $students, [$from->toDateString(), $to->toDateString()]);

        $rows = $report['rows']->map(fn ($r) => [...$r, 'removed' => $r['student']->pivot->status === Enrollment::REMOVED]);
        $totalStudents = $rows->count();

        // FR-RPT-05: search (name or ID number).
        $status = $request->input('status') ?: 'all';
        $search = trim((string) $request->input('q'));
        $needle = Str::lower($search);
        if ($needle !== '') {
            $rows = $rows->filter(fn ($r) => Str::contains(Str::lower($r['student']->name), $needle)
                || Str::contains(Str::lower($r['student']->listName()), $needle)
                || Str::contains(Str::lower((string) $r['student']->id_number), $needle));
        }
        $rows = $rows->values();

        // FR-RPT-06: one student, picked from the list or the only match of the search.
        $pickedId = $request->filled('student') ? (int) $request->input('student') : null;
        if ($pickedId !== null && ! $students->contains('id', $pickedId)) {
            throw ValidationException::withMessages(['student' => 'That student isn\'t in this class.']);
        }
        $single = $pickedId !== null
            ? $report['rows']->map(fn ($r) => [...$r, 'removed' => $r['student']->pivot->status === Enrollment::REMOVED])->firstWhere('student.id', $pickedId)
            : ($needle !== '' && $rows->count() === 1 ? $rows->first() : null);

        // FR-RPT-05: a status keeps the students with at least one such mark in the period
        // (a held session with no record is Absent), most first; equal counts keep the surname order.
        if ($status !== 'all') {
            $rows = $rows->filter(fn ($r) => $r[$status] > 0)
                ->values()->map(fn ($r, $i) => [...$r, '_i' => $i])
                ->sort(fn ($a, $b) => [$b[$status], $a['_i']] <=> [$a[$status], $b['_i']])
                ->values();
        }

        // FR-RPT-04: one grid column per held session. Two sessions on one day get "#1", "#2".
        $perDay = $report['sessions']->countBy(fn ($s) => $s->date->toDateString());
        $seen = [];
        $columns = $report['sessions']->map(function ($s) use ($perDay, &$seen) {
            $day = $s->date->toDateString();
            $seen[$day] = ($seen[$day] ?? 0) + 1;

            $tag = $perDay[$day] > 1 ? '#'.$seen[$day] : '';

            return [
                'id' => $s->id,
                'date' => $s->date->format('M j').($tag ? ' '.$tag : ''),
                'day' => $s->date->format('M j'),                       // printed header, line 1
                'sub' => $s->date->format('D').($tag ? ' '.$tag : ''),  // printed header, line 2
                'weekday' => $s->date->format('D'),
                'title' => $s->date->format('l, M j, Y').($s->title ? ' · '.$s->title : ''),
            ];
        })->values();

        $rangeLabel = self::rangeLabel($from, $to);
        $periodLabel = match ($period) {
            'day' => $from->format('l, M j, Y'),
            'month' => $wholeMonth ? $from->format('F Y') : $rangeLabel,
            'semester' => $class->semester.' semester ('.$from->format('M j, Y').' – '.$to->format('M j, Y').')',
            default => $rangeLabel,
        };

        $safe = fn ($v) => FileName::safe($v);
        $suffix = match ($period) {
            'day' => $from->format('Y-m-d'),
            'month' => $wholeMonth ? $from->format('Y-m') : $from->format('Y-m-d').'_to_'.$to->format('Y-m-d'),
            'semester' => $safe($class->school_term),
            default => $from->format('Y-m-d').'_to_'.$to->format('Y-m-d'),
        };

        $filters = array_filter([
            'Status' => $status !== 'all' ? self::STATUSES[$status] : null,
            'Search' => $search !== '' ? $search : null,
        ]);

        // FR-RPT-06: the student's own rows (one per held session), with Time In / Time Out.
        $studentRows = collect();
        if ($single) {
            $records = AttendanceRecord::where('student_id', $single['student']->id)
                ->whereIn('attendance_session_id', $report['sessions']->pluck('id'))
                ->get()->keyBy('attendance_session_id');
            $word = [ClassStatsService::PRESENT => 'present', ClassStatsService::LATE => 'late', ClassStatsService::ABSENT => 'absent'];
            $studentRows = $report['sessions']->map(fn ($s) => [
                'session' => $s,
                'mark' => $single['marks'][$s->id],
                'status' => $word[$single['marks'][$s->id]],
                'record' => $records->get($s->id),
            ])->when($status !== 'all', fn ($c) => $c->where('status', $status))->values();
        }

        $title = self::PERIODS[$period].' Attendance Summary';

        return [
            'class' => $class,
            'columns' => $columns,
            // Printed pages fit 8 date columns; longer periods continue in another table.
            'columnBlocks' => $columns->chunk(self::DATES_PER_PRINTED_TABLE)->map->values()->values(),
            'schoolName' => config('app.school_name'),
            'title' => $title,
            'fullTitle' => $title.' · '.($period === 'month' && $wholeMonth ? $from->format('F Y') : $rangeLabel), // FR-RPT-07
            'period' => $period,
            'periods' => self::PERIODS,
            'monthMode' => $monthMode,
            'monthModes' => self::MONTH_MODES,
            'date' => $date,
            'from' => $from,
            'to' => $to,
            // Links (the PDF, the period switch) carry the same period, range and filters.
            'query' => array_filter([
                'period' => $period,
                'date' => $period === 'day' ? $from->toDateString() : null,
                'from' => $period === 'week' || ($period === 'month' && ! $wholeMonth) ? $from->toDateString() : null,
                'to' => $period === 'week' || ($period === 'month' && ! $wholeMonth) ? $to->toDateString() : null,
                'month_mode' => $period === 'month' ? $monthMode : null,
                'month' => $wholeMonth ? $from->format('Y-m') : null,
                'status' => $status !== 'all' ? $status : null,
                'q' => $search !== '' ? $search : null,
                'student' => $pickedId,
            ]),
            'rows' => $rows,
            'held' => $report['held'],
            'status' => $status,
            'statuses' => self::STATUSES,
            'statusMark' => self::STATUS_MARKS[$status] ?? null,
            'search' => $search,
            'filtering' => $filters !== [] || $pickedId !== null,
            'filterLabel' => collect($filters)->map(fn ($v, $k) => $k.': '.$v)->implode(' · '),
            'totalStudents' => $totalStudents,
            'roster' => $students,
            'pickedId' => $pickedId,
            'single' => $single,
            'studentRows' => $studentRows,
            'header' => [
                'Subject' => $class->subject,
                'Section' => $class->section,
                'School term' => $class->school_term,
                // The period covered is part of the Semester line (no separate "Period" row).
                'Semester' => $period === 'semester' ? $periodLabel : $class->semester.' semester · '.$periodLabel,
                'Teacher' => $class->teacher->name,
                'Generated' => now()->format('M j, Y g:i A'),
            ],
            'studentHeader' => $single ? [
                'Student No.' => $single['student']->id_number,
                'Name' => $single['student']->listName().($single['removed'] ? ' (removed)' : ''),
                'Section' => $single['student']->sectionCode() ?? '—',
                'Subject' => $class->subject.' · '.$class->section,
                'School term' => $class->school_term.' · '.$class->semester.' semester',
                'Period' => $periodLabel,
            ] : [],
            'fileName' => $single
                ? $safe($class->subject).'_'.$safe((string) $single['student']->id_number).'_'.$safe($single['student']->lastName()).'_Attendance_'.$suffix
                : $safe($class->subject).'_'.$safe($class->section).'_Attendance_'.$suffix,
        ];
    }
}
