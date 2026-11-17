<?php

namespace App\Exports;

use App\Services\UserImportService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/** FR-ADM-14: the downloadable import template for one type (Student or Teacher), headings only. */
class UserImportTemplate implements FromArray, WithHeadings, ShouldAutoSize
{
    public function __construct(private string $type) {}

    public function headings(): array
    {
        return UserImportService::headings($this->type);
    }

    public function array(): array
    {
        return [];
    }
}
