<?php

namespace App\Http\Controllers\Admin;

use App\Exports\UserImportTemplate;
use App\Http\Controllers\Controller;
use App\Services\UserAccountService;
use App\Services\UserImportService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

/** FR-ADM-09: import Students and Teachers from CSV/Excel. */
class UserImportController extends Controller
{
    public const MAX_KB = 5120;

    public function create(UserAccountService $accounts)
    {
        return view('admin.users.import', [
            'result' => session('import_result'),
            'hasDefaultPassword' => $accounts->defaultPassword() !== null,
        ]);
    }

    public function store(Request $request, UserImportService $importer)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:'.self::MAX_KB],
        ], [
            'file.mimes' => 'Upload a .csv, .xlsx or .xls file.',
            'file.max' => 'The file may not be larger than 5 MB.',
        ]);

        $result = $importer->import($request->file('file'));

        $skipped = count($result['skipped']);

        return redirect()->route('admin.users.import')
            ->with('import_result', $result)
            ->with('status', $result['imported'].' imported, '.$skipped.' skipped.');
    }

    public function template(string $format)
    {
        return Excel::download(
            new UserImportTemplate,
            'attendease-user-import-template.'.$format,
            $format === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX,
        );
    }
}
