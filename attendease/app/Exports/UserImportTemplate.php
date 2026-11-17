<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/** FR-ADM-09: the downloadable import template, headings only. */
class UserImportTemplate implements FromArray, WithHeadings, ShouldAutoSize
{
    public const HEADINGS = ['Role', 'ID Number', 'Name', 'Email', 'Course', 'Year', 'Section', 'Department'];

    public function headings(): array
    {
        return self::HEADINGS;
    }

    public function array(): array
    {
        return [];
    }
}
