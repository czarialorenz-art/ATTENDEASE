<x-layouts.app :title="$class->subject">
    <div class="mb-5">
        <h1 class="h1">{{ $class->subject }} <span class="font-medium text-slate-500">· {{ $class->section }}</span></h1>
        <p class="text-sm text-slate-500">{{ $class->teacher->name }} · {{ $class->year_level }} · {{ $class->school_term }} · {{ $class->semester }} sem</p>
        @if ($class->schedules->isNotEmpty())
            <p class="mt-1 text-sm text-slate-500">
                @foreach ($class->schedules as $row){{ $row->dayName() }} {{ substr($row->start_time, 0, 5) }}–{{ substr($row->end_time, 0, 5) }}{{ $loop->last ? '' : ' · ' }}@endforeach
            </p>
        @endif
        @if ($class->locationLabel())
            <p class="mt-1 flex items-center gap-1 text-sm text-slate-500">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-7-6.2-7-11a7 7 0 1114 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                {{ $class->locationLabel() }}
            </p>
        @endif
    </div>

    {{-- The student's own attendance this semester --}}
    <div class="card">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <h2 class="h2">My attendance this semester</h2>
            <a href="{{ route('attendance.history', ['class_id' => $class->id]) }}" class="text-sm text-brand-600 hover:underline">Full history →</a>
        </div>

        <dl class="grid grid-cols-2 gap-3 text-center sm:grid-cols-4">
            <div class="rounded-lg bg-emerald-50 p-3"><dt class="text-xs text-emerald-700">Present</dt><dd class="text-2xl font-bold text-emerald-800">{{ $mine['present'] }}</dd></div>
            <div class="rounded-lg bg-amber-50 p-3"><dt class="text-xs text-amber-700">Late</dt><dd class="text-2xl font-bold text-amber-800">{{ $mine['late'] }}</dd></div>
            <div class="rounded-lg bg-red-50 p-3"><dt class="text-xs text-red-700">Absent</dt><dd class="text-2xl font-bold text-red-800">{{ $mine['absent'] }}</dd></div>
            <div class="rounded-lg bg-slate-50 p-3"><dt class="text-xs text-slate-500">Attendance rate</dt><dd class="text-2xl font-bold">{{ $mine['rate'] === null ? '—' : $mine['rate'].'%' }}</dd></div>
        </dl>
        <p class="mt-3 text-xs text-slate-500">Out of {{ $held }} {{ \Illuminate\Support\Str::plural('session', $held) }} held so far this semester.</p>

        <a href="{{ route('scanner') }}" class="btn-primary mt-4 w-full sm:w-auto">Scan attendance QR</a>
    </div>

    {{-- FR-ANN-03: announcements and cancellations from the teacher --}}
    <div class="card mt-6" id="announcements">
        <h2 class="h2 mb-3">Announcements</h2>
        <div class="space-y-3">
            @forelse ($announcements as $a)
                @include('classes._announcement-item', ['a' => $a])
            @empty
                <p class="text-sm text-slate-500">No announcements yet.</p>
            @endforelse
        </div>
    </div>
</x-layouts.app>
