<?php

namespace App\Http\Controllers;

use App\Exports\ArrayExport;
use App\Models\SchoolClass;
use App\Services\ClassListCheckService;
use App\Support\FileName;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

/**
 * FR-CLS-16: "Check class list" on the Students tab. The teacher uploads Name + Section and sees
 * who has joined. Only a check: nothing is saved; the last result is kept in the session.
 */
class ClassListCheckController extends Controller
{
    public static function sessionKey(SchoolClass $class): string
    {
        return 'class_list_check.'.$class->id;
    }

    public function check(Request $request, SchoolClass $class, ClassListCheckService $checker)
    {
        $this->authorize('teach', $class);

        $request->validate([
            'file' => ['required', 'file', 'max:2048', 'mimes:csv,txt,xlsx,xls'],
        ], [
            'file.required' => 'Choose a CSV or Excel file to check.',
            'file.max' => 'The file must be 2 MB or smaller.',
            'file.mimes' => 'Upload a .csv or .xlsx file.',
        ]);

        $result = $checker->check($class, $request->file('file'));
        $request->session()->put(self::sessionKey($class), $result);

        return redirect()->to(route('classes.students', $class).'#class-list-check')
            ->with('status', 'Class list checked: '.$result['rows'].' '.str('row')->plural($result['rows']).'.');
    }

    public function template(SchoolClass $class, string $format)
    {
        $this->authorize('teach', $class);

        // Section is optional, and one file can hold several sections (FR-CLS-16).
        return Excel::download(
            new ArrayExport(['Name', 'Section (optional)'], [
                ['Loreno, Maria Teresa', '4-B'],
                ['Albao, John Andrei', '4-B'],
                ['Dela Cruz, Juan', '4-A'],
                ['Terrado, Precious A.', '4-A'],
                ['Reyes, Ana', ''],
            ]),
            'class-list-template.'.$format,
            $format === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX,
        );
    }

    /** The last result as CSV: Group, Name, Section (from the file), Student No., Section on record, Row. */
    public function result(Request $request, SchoolClass $class)
    {
        $this->authorize('teach', $class);
        $result = $request->session()->get(self::sessionKey($class));
        abort_unless($result, 404);

        $rows = [];
        foreach (ClassListCheckService::GROUPS as $key => $label) {
            foreach ($result['groups'][$key] as $r) {
                $rows[] = [$label, $r['name'], $r['section'], $r['id_number'] ?? '', $r['student_section'] ?? '',
                    (($r['sheet'] ?? null) ? $r['sheet'].' ' : '').'row '.$r['row']];
            }
        }
        foreach ($result['missing_name'] as $where) {
            $rows[] = ['Missing name', '', '', '', '', $where];
        }

        return Excel::download(
            new ArrayExport(['Group', 'Name', 'Section', 'Student No.', 'Section on record', 'Row'], $rows),
            FileName::safe($class->subject).'_'.FileName::safe($class->section).'_Class_list_check.csv',
            ExcelFormat::CSV,
        );
    }

    public function clear(Request $request, SchoolClass $class)
    {
        $this->authorize('teach', $class);
        $request->session()->forget(self::sessionKey($class));

        return redirect()->route('classes.students', $class);
    }
}
