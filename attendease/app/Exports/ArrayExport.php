<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/** A simple sheet: headings plus rows (FR-CLS-16 template and result downloads). */
class ArrayExport implements FromArray, WithHeadings, ShouldAutoSize
{
    public function __construct(private array $headings, private array $rows = []) {}

    public function headings(): array
    {
        return $this->headings;
    }

    public function array(): array
    {
        return $this->rows;
    }
}
