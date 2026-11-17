<?php

namespace App\Http\Controllers\Admin;

use App\Exports\UserImportTemplate;
use App\Http\Controllers\Controller;
use App\Services\UserAccountService;
use App\Services\UserImportService;
use App\Support\StudentInfo;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

/** FR-ADM-09, FR-ADM-14: import Students or Teachers from CSV/Excel. */
class UserImportController extends Controller
{
    public const MAX_KB = 5120;

    public function create(Request $request, UserAccountService $accounts)
    {
        $result = session('import_result');
        // A failed submit's choice, then a type asked for in the link, then the last import's type.
        $type = old('type', $request->query('type', $result['type'] ?? ''));

        return view('admin.users.import', [
            'result' => $result,
            'type' => array_key_exists((string) $type, UserImportService::TYPES) ? $type : '',
            'hasDefaultPassword' => $accounts->defaultPassword() !== null,
        ]);
    }

    public function store(Request $request, UserImportService $importer)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(UserImportService::TYPES))],
            // FR-ADM-16: optional values for every student row; blank = "From the file".
            'course' => ['nullable', Rule::in(array_keys(StudentInfo::courses()))],
            'year_level' => ['nullable', 'integer', Rule::in(StudentInfo::yearLevels())],
            'section' => ['nullable', Rule::in(StudentInfo::sections())],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:'.self::MAX_KB],
        ], [
            'type.required' => 'Choose whether you are importing Students or Teachers.',
            'file.mimes' => 'Upload a .csv, .xlsx or .xls file.',
            'file.max' => 'The file may not be larger than 5 MB.',
        ], ['year_level' => 'year level']);

        $fixed = [
            'course' => $data['course'] ?? null,
            'year_level' => isset($data['year_level']) ? (int) $data['year_level'] : null,
            'section' => $data['section'] ?? null,
        ];

        $result = $importer->import($request->file('file'), $data['type'], $fixed);

        return redirect()->route('admin.users.import')
            ->with('import_result', $result)
            ->with('status', $result['imported'].' imported, '.count($result['skipped']).' skipped.');
    }

    public function template(string $type, string $format)
    {
        return Excel::download(
            new UserImportTemplate($type),
            'attendease-'.$type.'-import-template.'.$format,
            $format === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX,
        );
    }
}
