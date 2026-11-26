<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\BeforeSheet;

/**
 * FR-CLS-16: reads EVERY sheet of a class list file as raw rows, with the sheet's name, so one
 * workbook can hold several sections (one sheet each, or mixed in one sheet).
 */
class ClassListImport implements ToCollection, WithEvents
{
    /** @var list<array{title: string, rows: Collection}> */
    public array $sheets = [];

    private string $title = '';

    public function registerEvents(): array
    {
        return [BeforeSheet::class => function (BeforeSheet $event) {
            $this->title = (string) $event->getSheet()->getDelegate()->getTitle();
        }];
    }

    public function collection(Collection $collection): void
    {
        $this->sheets[] = ['title' => $this->title, 'rows' => $collection];
    }
}
