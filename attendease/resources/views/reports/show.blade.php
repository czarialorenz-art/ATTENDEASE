<x-layouts.app :title="$class->subject.' report'">
    <x-class-nav :class="$class" active="report" />

    @php
        $markClass = ['P' => 'bg-emerald-100 text-emerald-800', 'L' => 'bg-amber-100 text-amber-800', 'A' => 'bg-red-100 text-red-800'];
        $markLabel = ['P' => 'Present', 'L' => 'Late', 'A' => 'Absent'];
        $statusLabel = $statuses[$status];
        $errorText = fn ($f) => $errors->first($f);
    @endphp

    {{-- FR-RPT-07: period picker (Day / Week From–To / Month whole or custom / Semester), FR-RPT-05/06 filters --}}
    <form method="GET" action="{{ route('reports.show', $class) }}" class="card mb-5 space-y-4" id="period-form" novalidate>
        <div class="flex flex-col gap-4 lg:flex-row lg:flex-wrap lg:items-end">
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

            {{-- Day --}}
            <div data-for="day" @class(['hidden' => $period !== 'day'])>
                <label for="date" class="label">Date</label>
                <input type="date" id="date" name="date" value="{{ $date->toDateString() }}" @class(['input', 'input-error' => $errors->has('date')]) @disabled($period !== 'day')>
                @error('date')<p class="error">{{ $message }}</p>@enderror
            </div>

            {{-- Month: whole month or custom range --}}
            <fieldset data-for="month" @class(['hidden' => $period !== 'month'])>
                <legend class="label">Month</legend>
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    @foreach ($monthModes as $key => $label)
                        <label class="inline-flex items-center gap-1.5">
                            <input type="radio" name="month_mode" value="{{ $key }}" @checked($monthMode === $key) @disabled($period !== 'month')> {{ $label }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <div data-for="month-whole" @class(['hidden' => ! ($period === 'month' && $monthMode === 'whole')])>
                <label for="month" class="label">Month</label>
                <input type="month" id="month" name="month" value="{{ $from->format('Y-m') }}" @class(['input', 'input-error' => $errors->has('month')])
                    @disabled(! ($period === 'month' && $monthMode === 'whole'))>
                @error('month')<p class="error">{{ $message }}</p>@enderror
            </div>

            {{-- Week, or a custom range in a month: From and To --}}
            <div data-for="range" @class(['flex gap-3', 'hidden' => ! ($period === 'week' || ($period === 'month' && $monthMode === 'custom'))])>
                @php($rangeOn = $period === 'week' || ($period === 'month' && $monthMode === 'custom'))
                <div>
                    <label for="from" class="label">From</label>
                    <input type="date" id="from" name="from" value="{{ $from->toDateString() }}" @class(['input', 'input-error' => $errors->has('from')]) @disabled(! $rangeOn)>
                    @error('from')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="to" class="label">To</label>
                    <input type="date" id="to" name="to" value="{{ $to->toDateString() }}" @class(['input', 'input-error' => $errors->has('to')]) @disabled(! $rangeOn)>
                    @error('to')<p class="error max-w-64">{{ $message }}</p>@enderror
                </div>
            </div>
            <p data-for="range-help" @class(['help lg:basis-full', 'hidden' => ! $rangeOn])>
                <span data-help="week" @class(['hidden' => $period !== 'week'])>A week is up to 7 days, e.g. Monday to Friday.</span>
                <span data-help="month" @class(['hidden' => $period !== 'month'])>Both dates must be in the same month.</span>
            </p>
        </div>

        {{-- FR-RPT-05: search and status; FR-RPT-06: pick one student --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-end">
            <div class="min-w-0 sm:flex-1">
                <label for="report-search" class="label">Search</label>
                <input type="search" id="report-search" name="q" value="{{ $search }}" maxlength="100" autocomplete="off"
                    placeholder="Name or ID number" @class(['input', 'input-error' => $errors->has('q')])>
                @error('q')<p class="error">{{ $message }}</p>@enderror
            </div>
            <div class="sm:w-60">
                <label for="report-student" class="label">Student</label>
                <select id="report-student" name="student" @class(['input', 'input-error' => $errors->has('student')])>
                    <option value="">All students</option>
                    @foreach ($roster as $s)
                        <option value="{{ $s->id }}" @selected($pickedId === $s->id)>{{ $s->listName() }}</option>
                    @endforeach
                </select>
                @error('student')<p class="error">{{ $message }}</p>@enderror
            </div>
            <div class="sm:w-36">
                <label for="report-status" class="label">Status</label>
                <select id="report-status" name="status" @class(['input', 'input-error' => $errors->has('status')])>
                    @foreach ($statuses as $key => $label)
                        <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('status')<p class="error">{{ $message }}</p>@enderror
            </div>
            <div class="flex gap-2">
                <button class="btn-primary">Show report</button>
                <a href="{{ route('reports.show', $class) }}" class="btn-secondary">Clear</a>
            </div>
            @error('period')<p class="error sm:basis-full">{{ $message }}</p>@enderror
        </div>
    </form>

    <div class="card">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="text-sm font-semibold text-brand-700">{{ $schoolName }}</div>
                <h2 class="text-lg font-bold" data-report-title>{{ $single ? 'Student Attendance Record' : $title }} · {{ \Illuminate\Support\Str::after($fullTitle, ' · ') }}</h2>
                <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-sm">
                    @foreach ($single ? $studentHeader : $header as $label => $value)
                        <dt class="text-slate-500">{{ $label }}</dt><dd>{{ $value }}</dd>
                    @endforeach
                    @if ($filterLabel !== '')
                        <dt class="text-slate-500">Filter</dt><dd class="font-medium" data-filter-label>{{ $filterLabel }}</dd>
                    @endif
                </dl>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('reports.pdf', [$class, ...$query]) }}" class="btn-primary">Download PDF</a>
            </div>
        </div>

        @if ($single)
            {{-- FR-RPT-06: one student's attendance record --}}
            <div class="table-wrap" data-student-record>
                <table class="table">
                    <thead><tr><th>Date</th><th>Day</th><th>Session title</th><th>Status</th><th>Time In</th><th>Time Out</th></tr></thead>
                    <tbody>
                        @forelse ($studentRows as $r)
                            <tr>
                                <td class="whitespace-nowrap">{{ $r['session']->date->format('M j, Y') }}</td>
                                <td>{{ $r['session']->date->format('l') }}</td>
                                <td>{{ $r['session']->title ?: '—' }}</td>
                                <td class="whitespace-nowrap"><span class="badge-{{ $r['status'] }}" data-status="{{ $r['status'] }}">{{ ucfirst($r['status']) }}</span></td>
                                <td class="whitespace-nowrap">{{ $r['record']?->time_in_at?->format('g:i A') ?? '—' }}</td>
                                <td class="whitespace-nowrap">{{ $r['record']?->time_out_at?->format('g:i A') ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-6 text-center text-slate-500">
                                {{ $status !== 'all' ? 'No '.$statusLabel.' dates in this period.' : 'No sessions were held in this period.' }}
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <dl class="mt-4 grid grid-cols-2 gap-3 text-center sm:grid-cols-5" data-student-totals>
                <div class="rounded-lg bg-emerald-50 p-3"><dt class="text-xs text-emerald-700">Present</dt><dd class="text-xl font-bold text-emerald-800">{{ $single['present'] }}</dd></div>
                <div class="rounded-lg bg-amber-50 p-3"><dt class="text-xs text-amber-700">Late</dt><dd class="text-xl font-bold text-amber-800">{{ $single['late'] }}</dd></div>
                <div class="rounded-lg bg-red-50 p-3"><dt class="text-xs text-red-700">Absent</dt><dd class="text-xl font-bold text-red-800">{{ $single['absent'] }}</dd></div>
                <div class="rounded-lg bg-slate-50 p-3"><dt class="text-xs text-slate-500">Sessions held</dt><dd class="text-xl font-bold">{{ $held }}</dd></div>
                <div class="rounded-lg bg-slate-50 p-3"><dt class="text-xs text-slate-500">Attendance rate</dt><dd class="text-xl font-bold">{{ $single['rate'] === null ? '—' : $single['rate'].'%' }}</dd></div>
            </dl>
        @else
            {{-- FR-RPT-05 --}}
            <p class="mb-2 text-sm text-slate-500" data-showing>
                Showing {{ $rows->count() }} of {{ $totalStudents }} {{ \Illuminate\Support\Str::plural('student', $totalStudents) }}@if ($status !== 'all') with at least one {{ $statusLabel }}@endif
            </p>

            {{-- FR-RPT-04: attendance sheet grid, one column per session date. Scrolls sideways; the name stays in view.
                 FR-RPT-05: with a status filter, only that status is marked and only its total is shown. --}}
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
                            @if ($status === 'all')
                                <th class="border-l border-slate-200 text-center">Present</th>
                                <th class="text-center">Late</th>
                                <th class="text-center">Absent</th>
                            @else
                                <th class="border-l border-slate-200 text-center" data-total-column>{{ $statusLabel }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $i => $r)
                            <tr>
                                <td>{{ $i + 1 }}</td>
                                <td class="font-mono text-xs whitespace-nowrap">{{ $r['student']->id_number }}</td>
                                <td class="sticky left-0 z-10 bg-white whitespace-nowrap">{{ $r['student']->listName() }} @if ($r['removed'])<span class="badge-removed">removed</span>@endif</td>
                                <td class="whitespace-nowrap">{{ $r['student']->sectionCode() ?? '—' }}</td>
                                @foreach ($columns as $col)
                                    @php($mark = $r['marks'][$col['id']])
                                    <td class="px-1.5 text-center">
                                        @if ($statusMark === null || $mark === $statusMark)
                                            <span class="inline-grid h-6 w-6 place-items-center rounded text-xs font-bold {{ $markClass[$mark] }}"
                                                  title="{{ $markLabel[$mark] }} · {{ $col['title'] }}" data-mark="{{ $mark }}">{{ $mark }}</span>
                                        @else
                                            <span class="text-slate-300" data-mark="-">–</span>
                                        @endif
                                    </td>
                                @endforeach
                                @if ($status === 'all')
                                    <td class="border-l border-slate-200 text-center">{{ $r['present'] }}</td>
                                    <td class="text-center">{{ $r['late'] }}</td>
                                    <td class="text-center">{{ $r['absent'] }}</td>
                                @else
                                    <td class="border-l border-slate-200 text-center font-semibold">{{ $r[$status] }}</td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ 5 + $columns->count() + ($status === 'all' ? 2 : 0) }}" class="py-6 text-center text-slate-500">
                                @if ($filtering && $totalStudents > 0)
                                    No students match these filters for this period. Try another status or search, or <a href="{{ route('reports.show', $class) }}" class="text-brand-600 underline">clear the filters</a>.
                                @else
                                    No students.
                                @endif
                            </td></tr>
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
        @endif
    </div>

    @push('scripts')
    <script>
        // FR-RPT-07: show only the date fields for the chosen period (and month mode), without reloading.
        // Hidden fields are disabled so they aren't sent.
        (() => {
            const form = document.getElementById('period-form');
            const show = (selector, on) => form.querySelectorAll(selector).forEach((el) => {
                el.classList.toggle('hidden', !on);
                el.querySelectorAll('input').forEach((i) => { i.disabled = !on; });
            });
            function update() {
                const period = form.querySelector('[name="period"]:checked')?.value;
                const mode = form.querySelector('[name="month_mode"]:checked')?.value || 'whole';
                const range = period === 'week' || (period === 'month' && mode === 'custom');
                show('[data-for="day"]', period === 'day');
                show('[data-for="month"]', period === 'month');
                show('[data-for="month-whole"]', period === 'month' && mode === 'whole');
                show('[data-for="range"]', range);
                form.querySelector('[data-for="range-help"]').classList.toggle('hidden', !range);
                form.querySelectorAll('[data-help]').forEach((h) => h.classList.toggle('hidden', h.dataset.help !== period));
            }
            form.addEventListener('change', (e) => {
                if (e.target.name === 'period' || e.target.name === 'month_mode') update();
                // A week: picking From moves To to 6 days later.
                if (e.target.name === 'from' && form.querySelector('[name="period"]:checked')?.value === 'week' && e.target.value) {
                    const d = new Date(e.target.value + 'T00:00:00');
                    d.setDate(d.getDate() + 6);
                    const pad = (n) => String(n).padStart(2, '0'); // local date, not UTC
                    form.querySelector('[name="to"]').value = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
                }
            });
        })();
    </script>
    @endpush
</x-layouts.app>
