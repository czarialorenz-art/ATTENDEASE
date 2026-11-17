<x-layouts.app :title="$class->subject">
    <x-class-nav :class="$class" active="overview" />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card lg:col-span-1">
            <div class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Class code</div>
            <div class="mt-2 flex items-center gap-2">
                <span id="class-code" class="font-mono text-3xl font-bold tracking-[0.2em] text-brand-700">{{ $class->code }}</span>
                <button type="button" class="btn-secondary btn-sm" data-copy="#class-code">Copy</button>
            </div>
            <p class="mt-2 text-sm text-slate-500">Students enter this on their dashboard to ask to join.</p>

            <dl class="mt-5 grid grid-cols-2 gap-3 text-center">
                <div class="rounded-lg bg-slate-50 p-3"><dt class="text-xs text-slate-500">Students</dt><dd class="text-xl font-bold">{{ $studentCount }}</dd></div>
                <a href="{{ route('classes.students', $class) }}" class="rounded-lg bg-amber-50 p-3 hover:bg-amber-100"><dt class="text-xs text-amber-700">Pending</dt><dd class="text-xl font-bold text-amber-800">{{ $pendingCount }}</dd></a>
            </dl>
        </div>

        <div class="card lg:col-span-2">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="h2">Recent sessions</h2>
                <a href="{{ route('sessions.index', $class) }}" class="btn-primary btn-sm">+ New session</a>
            </div>
            @forelse ($recentSessions as $s)
                <a href="{{ route('sessions.show', $s) }}" class="-mx-2 flex items-center justify-between rounded-lg px-2 py-2 hover:bg-slate-50">
                    <span><span class="font-medium">{{ $s->date->format('D, M j, Y') }}</span> <span class="text-sm text-slate-500">{{ $s->title }}</span></span>
                    <span class="text-xs text-slate-500">
                        @if ($s->openStage()) <span class="badge-approved">{{ \App\Models\AttendanceSession::stageLabel($s->openStage()) }} open</span>
                        @elseif ($s->time_out_closed_at) Completed @elseif ($s->time_in_opened_at) In progress @else Not started @endif
                    </span>
                </a>
            @empty
                <p class="text-sm text-slate-500">No sessions yet.</p>
            @endforelse

            <div class="mt-6 mb-2 flex items-baseline justify-between gap-2">
                <h2 class="h2">Weekly schedule</h2>
                <a href="{{ route('classes.edit', $class) }}#schedules" class="text-sm font-medium text-brand-600 hover:underline">Manage schedule</a>
            </div>
            @forelse ($class->schedules as $row)
                <div class="text-sm">{{ $row->dayName() }} · {{ $row->timeRange() }}</div>
            @empty
                <p class="text-sm text-slate-500">No schedule set. <a href="{{ route('classes.edit', $class) }}#schedules" class="text-brand-600 hover:underline">Add one</a>.</p>
            @endforelse
            <p class="mt-2 text-sm text-slate-500">
                Classroom: {{ $class->locationLabel() ?? 'not set' }}
                @unless ($class->locationLabel())<a href="{{ route('classes.edit', $class) }}" class="text-brand-600 hover:underline">Add it</a>@endunless
            </p>
        </div>
    </div>
</x-layouts.app>
