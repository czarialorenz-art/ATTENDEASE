<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Services\ClassStatsService;
use App\Support\FileName;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * FR-RPT-01..03: attendance summary, teacher only.
 * The teacher picks the period: a day, a week (Mon–Sun), a month, or the whole semester.
 */
class ReportController extends Controller
{
    public const PERIODS = [
        'day' => 'Daily',
        'week' => 'Weekly',
        'month' => 'Monthly',
        'semester' => 'Semester',
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

        return Pdf::loadView('pdf.attendance-report', $d)
            ->setPaper('a4', 'portrait')
            ->download($d['fileName'].'.pdf');
    }

    /**
     * Resolves the chosen period to an inclusive date range.
     *
     * @return array{period: string, date: CarbonImmutable, from: CarbonImmutable, to: CarbonImmutable}
     */
    private function range(Request $request, SchoolClass $class): array
    {
        $request->validate([
            'period' => ['nullable', Rule::in(array_keys(self::PERIODS))],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $period = $request->input('period', 'semester');
        $date = $request->filled('date')
            ? CarbonImmutable::createFromFormat('Y-m-d', $request->input('date'))->startOfDay()
            : CarbonImmutable::today();

        [$from, $to] = match ($period) {
            'day' => [$date, $date],
            'week' => [$date->startOfWeek(CarbonImmutable::MONDAY), $date->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay()],
            'month' => [$date->startOfMonth(), $date->endOfMonth()->startOfDay()],
            default => [CarbonImmutable::parse($class->semester_start), CarbonImmutable::parse($class->semester_end)],
        };

        return compact('period', 'date', 'from', 'to');
    }

    private function data(Request $request, SchoolClass $class): array
    {
        ['period' => $period, 'date' => $date, 'from' => $from, 'to' => $to] = $this->range($request, $class);

        // Approved students plus removed ones, whose history is kept for reports (FR-CLS-06).
        $students = $class->students()
            ->wherePivotIn('status', [Enrollment::APPROVED, Enrollment::REMOVED])
            ->get()
            ->sortBy(fn ($u) => $u->surnameSortKey(), SORT_NATURAL) // by surname, like a class record
            ->values();

        $report = $this->stats->termReport($class, $students, [$from->toDateString(), $to->toDateString()]);

        $rows = $report['rows']->map(fn ($r) => [...$r, 'removed' => $r['student']->pivot->status === Enrollment::REMOVED]);

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

        $periodLabel = match ($period) {
            'day' => $from->format('l, M j, Y'),
            'week' => 'Week of '.$from->format('M j').' – '.$to->format('M j, Y'),
            'month' => $from->format('F Y'),
            default => $class->semester.' semester ('.$from->format('M j, Y').' – '.$to->format('M j, Y').')',
        };

        $safe = fn ($v) => FileName::safe($v);
        $suffix = match ($period) {
            'day' => $from->format('Y-m-d'),
            'week' => 'Week_'.$from->format('Y-m-d'),
            'month' => $from->format('Y-m'),
            default => $safe($class->school_term),
        };

        return [
            'class' => $class,
            'columns' => $columns,
            // Printed pages fit about 15 date columns; longer periods continue in another table.
            'columnBlocks' => $columns->chunk(self::DATES_PER_PRINTED_TABLE)->map->values()->values(),
            'schoolName' => config('app.school_name'),
            'title' => self::PERIODS[$period].' Attendance Summary',
            'period' => $period,
            'periods' => self::PERIODS,
            'date' => $date,
            'query' => ['period' => $period, 'date' => $date->toDateString()],
            'rows' => $rows,
            'held' => $report['held'],
            'header' => [
                'Subject' => $class->subject,
                'Section' => $class->section,
                'School term' => $class->school_term,
                // The period covered is part of the Semester line (no separate "Period" row).
                'Semester' => $period === 'semester' ? $periodLabel : $class->semester.' semester · '.$periodLabel,
                'Teacher' => $class->teacher->name,
                'Generated' => now()->format('M j, Y g:i A'),
            ],
            'fileName' => $safe($class->subject).'_'.$safe($class->section).'_Attendance_'.$suffix,
        ];
    }
}
