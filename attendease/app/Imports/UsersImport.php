<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * FR-ADM-09: reads the first sheet of the uploaded CSV/Excel file. Row 1 holds the headings
 * ("ID Number" becomes id_number); the rows are checked and saved by UserImportService.
 */
class UsersImport implements ToCollection, WithHeadingRow
{
    /** @var Collection<int, Collection<string, mixed>> */
    public Collection $rows;

    public function __construct()
    {
        $this->rows = collect();
    }

    public function collection(Collection $collection): void
    {
        if ($this->rows->isEmpty()) { // first sheet only
            $this->rows = $collection;
        }
    }
}
