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
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

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

    /** Date columns per table in the PDF and Word downloads (portrait A4). */
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

    public function docx(Request $request, SchoolClass $class)
    {
        $this->authorize('teach', $class);
        $d = $this->data($request, $class);

        $word = new PhpWord;
        $word->setDefaultFontName('Arial');
        $word->setDefaultFontSize(10);

        // Portrait A4 (11906 × 16838 twips) with 0.6" margins.
        $section = $word->addSection(['orientation' => 'portrait', 'pageSizeW' => 11906, 'pageSizeH' => 16838,
            'marginLeft' => 860, 'marginRight' => 860, 'marginTop' => 900, 'marginBottom' => 900]);
        $section->addText($d['schoolName'], ['bold' => true, 'size' => 14], ['alignment' => Jc::CENTER]);
        $section->addText($d['title'], ['bold' => true, 'size' => 12], ['alignment' => Jc::CENTER, 'spaceAfter' => 200]);

        foreach ($d['header'] as $label => $value) {
            $run = $section->addTextRun(['spaceAfter' => 0]);
            $run->addText($label.': ', ['bold' => true]);
            $run->addText((string) $value);
        }
        $section->addTextBreak();

        $word->addTableStyle('report', ['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 40], ['bgColor' => 'E5E7EB']);
        $small = ['size' => 8];
        $smallBold = ['size' => 8, 'bold' => true];
        $center = ['alignment' => Jc::CENTER, 'spaceAfter' => 0];
        $markColor = ['P' => '1E7B47', 'L' => 'B45309', 'A' => 'B42318'];

        // FR-RPT-04: attendance sheet grid. Long periods continue in further tables of 15 dates;
        // the totals are on the last one.
        $blocks = $d['columnBlocks']->isEmpty() ? collect([collect()]) : $d['columnBlocks'];
        foreach ($blocks as $b => $block) {
            $last = $b === $blocks->count() - 1;

            if ($blocks->count() > 1) {
                $section->addText('Dates '.($b * self::DATES_PER_PRINTED_TABLE + 1).'–'.($b * self::DATES_PER_PRINTED_TABLE + $block->count())
                    .' of '.$d['columns']->count(), ['bold' => true, 'size' => 9], ['spaceBefore' => $b ? 200 : 0, 'spaceAfter' => 60]);
            }

            $table = $section->addTable('report');
            $table->addRow(null, ['tblHeader' => true]);
            // Widths in twips: 4210 fixed + 8 dates x 520 + 1820 totals = 10190, the portrait text width.
            $widths = ['#' => 360, 'Student No.' => 1100, 'Name' => 2050, 'Section' => 700];
            foreach ($widths as $h => $w) {
                $table->addCell($w)->addText($h, $smallBold);
            }
            foreach ($block as $col) {
                $cell = $table->addCell(520);
                $cell->addText($col['day'], $smallBold, $center);
                $cell->addText($col['sub'], ['size' => 7], $center);
            }
            if ($last) {
                foreach (['P' => 380, 'L' => 380, 'A' => 380] as $h => $w) {
                    $table->addCell($w)->addText($h, $smallBold, $center);
                }
            }

            foreach ($d['rows'] as $i => $row) {
                $table->addRow();
                $table->addCell($widths['#'])->addText((string) ($i + 1), $small);
                $table->addCell($widths['Student No.'])->addText($row['student']->id_number, $small);
                $table->addCell($widths['Name'])->addText($row['student']->name.($row['removed'] ? ' (removed)' : ''), $small);
                $table->addCell($widths['Section'])->addText($row['student']->section ?: '—', $small);
                foreach ($block as $col) {
                    $mark = $row['marks'][$col['id']];
                    $table->addCell(520)->addText($mark, ['size' => 8, 'bold' => true, 'color' => $markColor[$mark]], $center);
                }
                if ($last) {
                    $table->addCell(380)->addText((string) $row['present'], $small, $center);
                    $table->addCell(380)->addText((string) $row['late'], $small, $center);
                    $table->addCell(380)->addText((string) $row['absent'], $small, $center);                }
            }
        }

        $section->addTextBreak();
        $section->addText('P = Present · L = Late · A = Absent. Sessions held in this period: '.$d['held'].'.', ['italic' => true, 'size' => 9]);

        $path = tempnam(sys_get_temp_dir(), 'rpt');
        IOFactory::createWriter($word, 'Word2007')->save($path);

        return response()->download($path, $d['fileName'].'.docx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])->deleteFileAfterSend();
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
            ->orderBy('name')
            ->get();

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
