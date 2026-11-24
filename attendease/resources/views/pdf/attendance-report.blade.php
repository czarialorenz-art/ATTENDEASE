<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Attendance report – {{ $class->subject }}</title>
    <style>
        @page { margin: 12mm 11mm; } /* portrait A4 */
        body { font-family: DejaVu Sans, sans-serif; font-size: 8pt; color: #111; }
        h1 { text-align: center; font-size: 14pt; margin: 0; }
        h2 { text-align: center; font-size: 11pt; margin: 2px 0 10px; font-weight: normal; }
        .meta { width: 100%; }
        .meta td { padding: 1px 10px 1px 0; vertical-align: top; }
        .meta .k { font-weight: bold; white-space: nowrap; width: 1%; }
        .block-title { margin: 12px 0 0; font-weight: bold; font-size: 9pt; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 6px; page-break-inside: auto; }
        table.data tr { page-break-inside: avoid; }
        table.data thead { display: table-header-group; }
        table.data th, table.data td { border: 1px solid #888; padding: 3px 3px; }
        table.data th { background: #e5e7eb; text-align: left; }
        th.d { text-align: center; font-size: 7pt; white-space: nowrap; width: 9mm; }
        th.d small { display: block; font-weight: normal; color: #555; font-size: 6.5pt; }
        .c { text-align: center; }
        .name { width: 38mm; } /* long names wrap onto a second line */
        .m { text-align: center; font-weight: bold; }
        .m-P { color: #1e7b47; background: #e3f5ea; }
        .m-L { color: #b45309; background: #fdf0d9; }
        .m-A { color: #b42318; background: #fde8e6; }
        .totals { border-left: 2px solid #555 !important; }
        .note { margin-top: 8px; font-style: italic; color: #444; }
    </style>
</head>
<body>
    <h1>{{ $schoolName }}</h1>
    <h2>{{ $title }}</h2>
    @if ($filtering){{-- FR-RPT-05: the filters used for this download --}}
        <p style="text-align:center; margin:-6px 0 8px; font-weight:bold;">{{ $filterLabel }} · {{ $rows->count() }} of {{ $totalStudents }} students</p>
    @endif

    <table class="meta">
        @foreach (array_chunk($header, 2, true) as $chunk) {{-- two pairs per row fit a portrait page --}}
            <tr>@foreach ($chunk as $k => $v)<td class="k">{{ $k }}:</td><td>{{ $v }}</td>@endforeach</tr>
        @endforeach
    </table>

    {{-- FR-RPT-04: attendance sheet grid. Long periods continue in further tables of
         {{ \App\Http\Controllers\ReportController::DATES_PER_PRINTED_TABLE }} dates; totals are on the last one. --}}
    @php
        $blocks = $columnBlocks->isEmpty() ? collect([collect()]) : $columnBlocks;
        $per = \App\Http\Controllers\ReportController::DATES_PER_PRINTED_TABLE;
    @endphp
    @foreach ($blocks as $b => $block)
        @php($last = $loop->last)
        @if ($blocks->count() > 1)
            <div class="block-title">Dates {{ $b * $per + 1 }}–{{ $b * $per + $block->count() }} of {{ $columns->count() }}</div>
        @endif
        <table class="data">
            <thead>
                <tr>
                    <th>#</th><th>Student No.</th><th class="name">Name</th><th>Section</th>
                    @foreach ($block as $col)
                        <th class="d">{{ $col['day'] }}<small>{{ $col['sub'] }}</small></th>
                    @endforeach
                    @if ($last)
                        <th class="c totals">P</th><th class="c">L</th><th class="c">A</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $i => $r)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>{{ $r['student']->id_number }}</td>
                        <td class="name">{{ $r['student']->listName() }}{{ $r['removed'] ? ' (removed)' : '' }}</td>
                        <td>{{ $r['student']->sectionCode() ?? '—' }}</td>
                        @foreach ($block as $col)
                            @php($mark = $r['marks'][$col['id']])
                            <td class="m m-{{ $mark }}">{{ $mark }}</td>
                        @endforeach
                        @if ($last)
                            <td class="c totals">{{ $r['present'] }}</td>
                            <td class="c">{{ $r['late'] }}</td>
                            <td class="c">{{ $r['absent'] }}</td>                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ 4 + $block->count() + ($last ? 3 : 0) }}" class="c">No students.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endforeach
    <div class="note">P = Present · L = Late · A = Absent. Sessions held in this period: {{ $held }}.</div>
</body>
</html>
