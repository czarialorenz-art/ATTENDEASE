<?php

namespace App\Http\Controllers\Admin;

use App\Exports\UserImportTemplate;
use App\Http\Controllers\Controller;
use App\Services\UserAccountService;
use App\Services\UserImportService;
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
        $type = old('type', $result['type'] ?? $request->query('type'));

        return view('admin.users.import', [
            'result' => $result,
            'type' => array_key_exists((string) $type, UserImportService::TYPES) ? $type : '',
            'hasDefaultPassword' => $accounts->defaultPassword() !== null,
        ]);
    }

    public function store(Request $request, UserImportService $importer)
    {
        $request->validate([
            'type' => ['required', Rule::in(array_keys(UserImportService::TYPES))],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:'.self::MAX_KB],
        ], [
            'type.required' => 'Choose whether you are importing Students or Teachers.',
            'file.mimes' => 'Upload a .csv, .xlsx or .xls file.',
            'file.max' => 'The file may not be larger than 5 MB.',
        ]);

        $result = $importer->import($request->file('file'), $request->input('type'));

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
