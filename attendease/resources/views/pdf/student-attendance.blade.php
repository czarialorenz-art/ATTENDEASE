<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    {{-- FR-RPT-06: one student's attendance record --}}
    <title>Attendance record – {{ $single['student']->name }}</title>
    <style>
        @page { margin: 14mm 13mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #111; }
        h1 { text-align: center; font-size: 14pt; margin: 0; }
        h2 { text-align: center; font-size: 11pt; margin: 2px 0 10px; font-weight: normal; }
        .meta { width: 100%; margin-bottom: 6px; }
        .meta td { padding: 1px 10px 1px 0; vertical-align: top; }
        .meta .k { font-weight: bold; white-space: nowrap; width: 1%; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.data thead { display: table-header-group; }
        table.data tr { page-break-inside: avoid; }
        table.data th, table.data td { border: 1px solid #888; padding: 4px 5px; }
        table.data th { background: #e5e7eb; text-align: left; }
        .s { font-weight: bold; }
        .s-present { color: #1e7b47; background: #e3f5ea; }
        .s-late { color: #b45309; background: #fdf0d9; }
        .s-absent { color: #b42318; background: #fde8e6; }
        table.totals { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.totals td { border: 1px solid #888; padding: 5px; text-align: center; width: 20%; }
        table.totals .k { font-size: 7.5pt; color: #444; display: block; }
        table.totals .v { font-size: 12pt; font-weight: bold; }
        .filter { text-align: center; margin: -6px 0 8px; font-weight: bold; }
    </style>
</head>
<body>
    <h1>{{ $schoolName }}</h1>
    <h2>Student Attendance Record · {{ \Illuminate\Support\Str::after($fullTitle, ' · ') }}</h2>
    @if ($status !== 'all')
        <p class="filter">Status: {{ $statuses[$status] }} dates only</p>
    @endif

    <table class="meta">
        @foreach (array_chunk($studentHeader, 2, true) as $chunk)
            <tr>@foreach ($chunk as $k => $v)<td class="k">{{ $k }}:</td><td>{{ $v }}</td>@endforeach</tr>
        @endforeach
    </table>

    <table class="data">
        <thead><tr><th>Date</th><th>Day</th><th>Session title</th><th>Status</th><th>Time In</th><th>Time Out</th></tr></thead>
        <tbody>
            @forelse ($studentRows as $r)
                <tr>
                    <td>{{ $r['session']->date->format('M j, Y') }}</td>
                    <td>{{ $r['session']->date->format('l') }}</td>
                    <td>{{ $r['session']->title ?: '—' }}</td>
                    <td class="s s-{{ $r['status'] }}">{{ ucfirst($r['status']) }}</td>
                    <td>{{ $r['record']?->time_in_at?->format('g:i A') ?? '—' }}</td>
                    <td>{{ $r['record']?->time_out_at?->format('g:i A') ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center">{{ $status !== 'all' ? 'No '.$statuses[$status].' dates in this period.' : 'No sessions were held in this period.' }}</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td><span class="k">Present</span><span class="v">{{ $single['present'] }}</span></td>
            <td><span class="k">Late</span><span class="v">{{ $single['late'] }}</span></td>
            <td><span class="k">Absent</span><span class="v">{{ $single['absent'] }}</span></td>
            <td><span class="k">Sessions held</span><span class="v">{{ $held }}</span></td>
            <td><span class="k">Attendance rate</span><span class="v">{{ $single['rate'] === null ? '—' : $single['rate'].'%' }}</span></td>
        </tr>
    </table>
    <p style="margin-top:8px; font-style:italic; color:#444;">A session held with no record counts as Absent. Generated {{ now()->format('M j, Y g:i A') }} · Teacher: {{ $class->teacher->name }}</p>
</body>
</html>
