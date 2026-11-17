@use('App\Http\Controllers\Admin\DashboardController', 'D')
@php
    $cards = [
        ['label' => 'Total Students', 'value' => $totals['students'], 'note' => 'active accounts'],
        ['label' => 'Total Teachers', 'value' => $totals['teachers'], 'note' => 'active accounts'],
        ['label' => 'Total Subjects', 'value' => $totals['subjects'], 'note' => 'across all classes'],
        ['label' => 'Total Sections', 'value' => $totals['sections'], 'note' => 'across all classes'],
    ];
    $statusBadge = [
        D::RECORDED => ['badge-present', 'Recorded'],
        D::NO_ATTENDANCE => ['badge-absent', 'No attendance'],
        D::UPCOMING => ['badge-neutral', 'Upcoming'],
        D::CANCELLED => ['badge-pending', 'Class cancelled'],
    ];
    $isToday = $date->isToday();
@endphp
<x-layouts.admin title="Dashboard">
    <div class="mb-5">
        <h1 class="h1">Dashboard</h1>
        <p class="text-sm text-slate-500">{{ now()->format('l, F j, Y · g:i A') }}</p>
    </div>

    {{-- FR-ADM-02: totals --}}
    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ($cards as $c)
            <div class="card" data-total="{{ \Illuminate\Support\Str::slug($c['label']) }}">
                <div class="text-sm font-medium text-slate-500">{{ $c['label'] }}</div>
                <div class="mt-1 text-3xl font-bold text-brand-700">{{ number_format($c['value']) }}</div>
                <div class="text-xs text-slate-400">{{ $c['note'] }}</div>
            </div>
        @endforeach
    </div>

    <section class="card">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="h2">Classes on {{ $date->format('l, M j, Y') }}@if ($isToday) <span class="font-normal text-slate-500">(today)</span>@endif</h2>
                <p class="text-sm text-slate-500">Classes scheduled on {{ $date->format('l') }}s whose semester includes this date.</p>
            </div>
            {{-- FR-ADM-03: date filter, today by default --}}
            <form method="GET" action="{{ route('admin.dashboard') }}" class="flex items-end gap-2">
                <div>
                    <label for="date" class="label">Date</label>
                    <input type="date" id="date" name="date" value="{{ $date->toDateString() }}" class="input" onchange="this.form.submit()">
                </div>
                <button class="btn-secondary">Show</button>
                @unless ($isToday)
                    <a href="{{ route('admin.dashboard') }}" class="btn-secondary">Today</a>
                @endunless
            </form>
        </div>

        {{-- FR-ADM-05: follow-up list --}}
        @if ($missing->isNotEmpty())
            <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="alert" data-missing-alert>
                <p class="font-semibold">
                    {{ $missing->count() }} {{ \Illuminate\Support\Str::plural('class', $missing->count()) }} with no attendance {{ $isToday ? 'yet today' : 'on '.$date->format('M j, Y') }}. Follow up with the teacher:
                </p>
                <ul class="mt-1 list-disc space-y-0.5 pl-5">
                    @foreach ($missing as $m)
                        <li>{{ $m['subject'] }} · {{ $m['section'] }} ({{ $m['time'] }}) — {{ $m['teacher'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Subject</th><th>Teacher</th><th>Room</th><th>Time</th><th class="text-center">Scanned In</th><th>Status</th></tr>
                </thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr data-status="{{ $r['status'] }}">
                            <td>
                                <div class="font-medium">{{ $r['subject'] }}</div>
                                <div class="text-xs text-slate-500">{{ $r['section'] }}</div>
                            </td>
                            <td class="whitespace-nowrap">{{ $r['teacher'] }}</td>
                            <td>{{ $r['room'] }}</td>
                            <td class="whitespace-nowrap">{{ $r['time'] }}</td>
                            <td class="text-center font-mono whitespace-nowrap" data-scanned>{{ $r['scanned_in'] }} / {{ $r['enrolled'] }}</td>
                            <td class="whitespace-nowrap"><span class="{{ $statusBadge[$r['status']][0] }}">{{ $statusBadge[$r['status']][1] }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-8 text-center text-slate-500">No classes are scheduled on {{ $date->format('l, M j, Y') }}.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-slate-500">
            Scanned In = students who scanned Time In / approved students in the class.
            <strong>Recorded</strong>: at least one attendance record that day · <strong>No attendance</strong>: the class has started but nothing is recorded ·
            <strong>Upcoming</strong>: not started yet · <strong>Class cancelled</strong>: the teacher announced no class that day.
        </p>
    </section>
</x-layouts.admin>
