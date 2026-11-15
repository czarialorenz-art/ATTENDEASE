<x-layouts.app :title="$class->subject.' report'">
    <x-class-nav :class="$class" active="report" />

    {{-- Period picker: day, week, month or semester --}}
    <form method="GET" class="card mb-5 flex flex-col gap-4 sm:flex-row sm:items-end" id="period-form">
        <fieldset>
            <legend class="label">Report period</legend>
            <div class="inline-flex flex-wrap rounded-lg border border-slate-300 bg-white p-1">
                @foreach ($periods as $key => $label)
                    <label class="cursor-pointer">
                        <input type="radio" name="period" value="{{ $key }}" class="peer sr-only" @checked($period === $key)>
                        <span class="block rounded-md px-3 py-1.5 text-sm font-medium text-slate-600 peer-checked:bg-brand-600 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-brand-600">
                            {{ ['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'semester' => 'Semester'][$key] }}
                        </span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div id="date-field" @class(['hidden' => $period === 'semester'])>
            <label for="date" class="label" id="date-label">
                {{ ['day' => 'Date', 'week' => 'Any day in the week', 'month' => 'Any day in the month'][$period] ?? 'Date' }}
            </label>
            <input type="date" id="date" name="date" value="{{ $date->toDateString() }}" class="input">
        </div>

        <button class="btn-primary sm:ml-auto">Show report</button>
    </form>

    <div class="card">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="text-sm font-semibold text-brand-700">{{ $schoolName }}</div>
                <h2 class="text-lg font-bold">{{ $title }}</h2>
                <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-sm">
                    @foreach ($header as $label => $value)
                        <dt class="text-slate-500">{{ $label }}</dt><dd>{{ $value }}</dd>
                    @endforeach
                </dl>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('reports.pdf', [$class, ...$query]) }}" class="btn-primary">Download PDF</a>
            </div>
        </div>

        {{-- FR-RPT-04: attendance sheet grid, one column per session date. Scrolls sideways; the name stays in view. --}}
        @php
            $markClass = ['P' => 'bg-emerald-100 text-emerald-800', 'L' => 'bg-amber-100 text-amber-800', 'A' => 'bg-red-100 text-red-800'];
            $markLabel = ['P' => 'Present', 'L' => 'Late', 'A' => 'Absent'];
        @endphp
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student No.</th>
                        <th class="sticky left-0 z-10 bg-slate-50">Name</th>
                        <th>Section</th>
                        @foreach ($columns as $col)
                            <th class="px-1.5 text-center normal-case" title="{{ $col['title'] }}" data-session-column>
                                <span class="block whitespace-nowrap">{{ $col['date'] }}</span>
                                <span class="block text-[10px] font-normal text-slate-400">{{ $col['weekday'] }}</span>
                            </th>
                        @endforeach
                        <th class="border-l border-slate-200 text-center">Present</th>
                        <th class="text-center">Late</th>
                        <th class="text-center">Absent</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $i => $r)
                        <tr>
                            <td>{{ $i + 1 }}</td>
                            <td class="font-mono text-xs whitespace-nowrap">{{ $r['student']->id_number }}</td>
                            <td class="sticky left-0 z-10 bg-white whitespace-nowrap">{{ $r['student']->name }} @if ($r['removed'])<span class="badge-removed">removed</span>@endif</td>
                            <td class="whitespace-nowrap">{{ $r['student']->section ?: '—' }}</td>
                            @foreach ($columns as $col)
                                @php($mark = $r['marks'][$col['id']])
                                <td class="px-1.5 text-center">
                                    <span class="inline-grid h-6 w-6 place-items-center rounded text-xs font-bold {{ $markClass[$mark] }}"
                                          title="{{ $markLabel[$mark] }} · {{ $col['title'] }}" data-mark="{{ $mark }}">{{ $mark }}</span>
                                </td>
                            @endforeach
                            <td class="border-l border-slate-200 text-center">{{ $r['present'] }}</td>
                            <td class="text-center">{{ $r['late'] }}</td>
                            <td class="text-center">{{ $r['absent'] }}</td>                        </tr>
                    @empty
                        <tr><td colspan="{{ 7 + $columns->count() }}" class="py-6 text-center text-slate-500">No students.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
            <span class="inline-flex items-center gap-1"><span class="inline-grid h-4 w-4 place-items-center rounded bg-emerald-100 text-[10px] font-bold text-emerald-800">P</span> Present</span>
            <span class="inline-flex items-center gap-1"><span class="inline-grid h-4 w-4 place-items-center rounded bg-amber-100 text-[10px] font-bold text-amber-800">L</span> Late</span>
            <span class="inline-flex items-center gap-1"><span class="inline-grid h-4 w-4 place-items-center rounded bg-red-100 text-[10px] font-bold text-red-800">A</span> Absent</span>
            <span>· Out of {{ $held }} {{ \Illuminate\Support\Str::plural('session', $held) }} held in this period.
            @if ($held === 0) No sessions were held. @endif</span>
        </p>
    </div>

    @push('scripts')
    <script>
        (() => {
            const labels = { day: 'Date', week: 'Any day in the week', month: 'Any day in the month' };
            const form = document.getElementById('period-form');
            form.addEventListener('change', e => {
                if (e.target.name !== 'period') return;
                const p = e.target.value;
                document.getElementById('date-field').classList.toggle('hidden', p === 'semester');
                if (labels[p]) document.getElementById('date-label').textContent = labels[p];
            });
        })();
    </script>
    @endpush
</x-layouts.app>
