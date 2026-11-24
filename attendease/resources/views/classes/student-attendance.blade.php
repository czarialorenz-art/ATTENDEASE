{{-- FR-CLS-15: one student's attendance in this class, opened from the Students tab's "…" menu (teacher only). --}}
<x-layouts.app :title="$student->name.' attendance'">
    <x-class-nav :class="$class" active="students" />

    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <a href="{{ route('classes.students', $class) }}" class="text-sm text-brand-600 hover:underline">← Students</a>
            <h1 class="h1 mt-1 flex flex-wrap items-center gap-2">
                {{ $student->listName() }}
                @include('classes._section-badge', ['student' => $student])
                @if ($removed)<span class="badge-removed">removed</span>@endif
            </h1>
            <p class="text-sm text-slate-500">{{ $student->id_number }}{{ $student->email ? ' · '.$student->email : '' }}</p>
        </div>
    </div>

    {{-- Same numbers as the student's own class page (ClassStatsService::termReport) --}}
    <div class="card mb-6">
        <h2 class="h2 mb-4">Attendance this semester</h2>
        <dl class="grid grid-cols-2 gap-3 text-center sm:grid-cols-4">
            <div class="rounded-lg bg-emerald-50 p-3"><dt class="text-xs text-emerald-700">Present</dt><dd class="text-2xl font-bold text-emerald-800" data-stat="present">{{ $mine['present'] }}</dd></div>
            <div class="rounded-lg bg-amber-50 p-3"><dt class="text-xs text-amber-700">Late</dt><dd class="text-2xl font-bold text-amber-800" data-stat="late">{{ $mine['late'] }}</dd></div>
            <div class="rounded-lg bg-red-50 p-3"><dt class="text-xs text-red-700">Absent</dt><dd class="text-2xl font-bold text-red-800" data-stat="absent">{{ $mine['absent'] }}</dd></div>
            <div class="rounded-lg bg-slate-50 p-3"><dt class="text-xs text-slate-500">Attendance rate</dt><dd class="text-2xl font-bold" data-stat="rate">{{ $mine['rate'] === null ? '—' : $mine['rate'].'%' }}</dd></div>
        </dl>
        <p class="mt-3 text-xs text-slate-500">Out of {{ $held }} {{ \Illuminate\Support\Str::plural('session', $held) }} held so far this semester ({{ $class->semester_start->format('M j') }} – {{ $class->semester_end->format('M j, Y') }}). A held session with no record counts as absent.</p>
    </div>

    <div class="card">
        <h2 class="h2 mb-1">Records</h2>
        <p class="mb-3 text-sm text-slate-500">Newest first. Open a session to change a status by hand.</p>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Date</th><th>Session</th><th>Status</th><th>Time In</th><th>Time Out</th><th><span class="sr-only">Open</span></th></tr></thead>
                <tbody>
                    @forelse ($records as $r)
                        @php($s = $r->attendanceSession)
                        <tr data-record="{{ $r->id }}">
                            <td class="whitespace-nowrap">{{ $s->date->format('D, M j, Y') }}</td>
                            <td>{{ $s->title ?: '—' }}</td>
                            <td><span class="badge-{{ $r->status }}">{{ ucfirst($r->status) }}</span></td>
                            <td class="whitespace-nowrap">{{ $r->time_in_at?->format('g:i A') ?? '—' }}</td>
                            <td class="whitespace-nowrap">{{ $r->time_out_at?->format('g:i A') ?? '—' }}</td>
                            <td class="text-right whitespace-nowrap">
                                <a href="{{ route('sessions.show', $s) }}#student-{{ $student->id }}" class="btn-secondary btn-sm">Open session</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-6 text-center text-slate-500">No attendance records in this class yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.app>
