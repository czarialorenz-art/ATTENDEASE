<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

/**
 * FR-ADM-09: reads the first sheet of the uploaded CSV/Excel file as raw rows. The heading
 * row is found and matched by UserImportService (FR-ADM-15: forgiving heading names), which
 * also checks and saves the rows.
 */
class UsersImport implements ToCollection
{
    /** @var Collection<int, Collection<int, mixed>> */
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
