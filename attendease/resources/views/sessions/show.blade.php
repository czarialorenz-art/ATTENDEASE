@use('App\Models\AttendanceSession', 'S')
@use('App\Models\ScanFlag')
@php
    $open = $session->openStage();
    $today = $session->isToday();
    $archived = $session->isArchived(); // FR-ATT-16: read-only until restored
@endphp
<x-layouts.app :title="'Session '.$session->date->format('M j')">
    <x-class-nav :class="$class" active="sessions" />

    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-lg font-bold">{{ $session->date->format('l, F j, Y') }} @if ($session->title)<span class="font-normal text-slate-500">· {{ $session->title }}</span>@endif</h2>
            <p class="text-sm text-slate-500">
                Time In: {{ $session->time_in_opened_at?->format('g:i A') ?? 'not opened' }}@if ($session->time_in_closed_at)–{{ $session->time_in_closed_at->format('g:i A') }}@endif
                · Time Out: {{ $session->time_out_opened_at?->format('g:i A') ?? 'not opened' }}@if ($session->time_out_closed_at)–{{ $session->time_out_closed_at->format('g:i A') }}@endif
            </p>
        </div>

        {{-- Stage controls (FR-ATT-02/03) --}}
        <div class="flex flex-wrap gap-2">
            @if ($archived)
                <form method="POST" action="{{ route('sessions.restore', $session) }}">@csrf<button class="btn-primary">Restore session</button></form>
                <form method="POST" action="{{ route('sessions.destroy', $session) }}"
                      data-confirm="Delete this session for good? Its attendance records, scans and flags will be deleted too. This can't be undone.">
                    @csrf @method('DELETE')<button class="btn-danger">Delete</button>
                </form>
            @elseif ($open)
                <a href="{{ route('sessions.live', $session) }}" class="btn-primary">Show QR ({{ S::stageLabel($open) }})</a>
                <form method="POST" action="{{ route('sessions.close', [$session, $open]) }}"
                      @if ($open === S::TIME_OUT) data-confirm="Close Time Out? Students missing a Time In or Time Out will be marked Absent." @endif>
                    @csrf<button class="btn-danger">Close {{ S::stageLabel($open) }}</button>
                </form>
            @elseif (! $session->time_out_closed_at)
                @if (! $session->time_out_opened_at)
                    <form method="POST" action="{{ route('sessions.open', [$session, S::TIME_IN]) }}">@csrf
                        <button class="{{ $session->time_in_opened_at ? 'btn-secondary' : 'btn-primary' }}" @disabled(! $today)>{{ $session->time_in_opened_at ? 'Reopen Time In' : 'Open Time In' }}</button>
                    </form>
                @endif
                @if ($session->time_in_closed_at)
                    <form method="POST" action="{{ route('sessions.open', [$session, S::TIME_OUT]) }}">@csrf
                        <button class="btn-primary" @disabled(! $today)>Open Time Out</button>
                    </form>
                @endif
            @else
                <span class="badge-neutral">Completed</span>
            @endif
            @unless ($archived)
                <a href="{{ route('sessions.edit', $session) }}" class="btn-secondary">Edit</a>
            @endunless
        </div>
    </div>
    @if (! $archived && ($semesterProblem = $class->semesterProblem($session->date)))
        {{-- FR-ATT-19: an existing session outside the semester (e.g. the semester dates changed later) --}}
        <p class="mb-4 rounded-lg bg-amber-50 px-4 py-2 text-sm text-amber-900" role="status">
            <strong>{{ $semesterProblem }}.</strong> This session doesn't count in semester totals or students' semester summaries.
            <a href="{{ route('classes.edit', $class) }}" class="font-semibold underline">Fix semester dates →</a>
        </p>
    @endif
    @if ($cancellation && ! $archived)
        <p class="mb-4 rounded-lg bg-red-50 px-4 py-2 text-sm text-red-800" role="status">
            <strong>You announced that class is cancelled on this date.</strong>
            Your students were told there's no class. If the class won't meet, archive this session; if plans changed, delete or edit the
            <a href="{{ route('announcements.index', $class) }}" class="underline">announcement</a>.
        </p>
    @endif
    @if ($archived)
        <p class="mb-4 rounded-lg bg-red-50 px-4 py-2 text-sm text-red-800" role="status">
            <strong>Archived on {{ $session->archived_at->format('M j, Y') }}.</strong>
            This session doesn't count in reports or students' records, and it will be <strong>deleted for good on {{ $session->deletesOn()->format('M j, Y') }}</strong>
            with its attendance and scans. Restore it to keep it.
        </p>
    @elseif (! ($today || $session->time_out_closed_at))
        <p class="mb-4 rounded-lg bg-amber-50 px-4 py-2 text-sm text-amber-800">Stages can only be opened on {{ $session->date->format('M j, Y') }} (Asia/Manila).</p>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Records + manual edits (FR-ATT-12) --}}
            <div class="card">
                <h2 class="h2 mb-3">Attendance</h2>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Student</th><th>Status</th><th>Time In</th><th>Time Out</th><th>Set status</th></tr></thead>
                        <tbody>
                            @forelse ($students as $student)
                                @php($r = $records->get($student->id))
                                @php($flagCount = $r ? $r->scanLogs->flatMap->flags->whereNull('cleared_at')->count() : 0)
                                <tr>
                                    <td>
                                        <div class="font-medium whitespace-nowrap">{{ $student->name }}</div>
                                        <div class="font-mono text-xs text-slate-500">{{ $student->id_number }}</div>
                                    </td>
                                    <td class="whitespace-nowrap">
                                        @if ($r)<span class="badge-{{ $r->status }}">{{ ucfirst($r->status) }}</span>
                                            @if ($r->source === 'manual')<span class="badge-neutral">manual</span>@endif
                                            @if ($flagCount)<span class="badge-flag" title="Unreviewed flags">⚑ {{ $flagCount }}</span>@endif
                                        @else <span class="text-slate-400">—</span> @endif
                                    </td>
                                    <td class="whitespace-nowrap">{{ $r?->time_in_at?->format('g:i:s A') ?? '—' }}</td>
                                    <td class="whitespace-nowrap">{{ $r?->time_out_at?->format('g:i:s A') ?? '—' }}</td>
                                    <td>
                                        @if ($archived)
                                            <span class="text-xs text-slate-400">Restore to edit</span>
                                        @else
                                        <form method="POST" action="{{ route('sessions.manual', $session) }}" class="flex gap-1">
                                            @csrf
                                            <input type="hidden" name="student_id" value="{{ $student->id }}">
                                            <select name="status" class="input py-1 text-xs" aria-label="Status for {{ $student->name }}">
                                                @foreach (\App\Models\AttendanceRecord::STATUSES as $st)
                                                    <option value="{{ $st }}" @selected($r?->status === $st)>{{ ucfirst($st) }}</option>
                                                @endforeach
                                            </select>
                                            <button class="btn-secondary btn-sm">Set</button>
                                        </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="py-6 text-center text-slate-500">No approved students yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Flags (FR-ATT-11) --}}
            <div class="card">
                <h2 class="h2 mb-1">Flagged scans</h2>
                <p class="mb-3 text-sm text-slate-500">Flags never block a scan. Review each one and clear it if it's fine.</p>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Student</th><th>Reason</th><th>Stage</th><th>Distance</th><th>IP</th><th>Time</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($flags as $f)
                                <tr @class(['opacity-60' => $f->isCleared()])>
                                    <td class="whitespace-nowrap">{{ $f->scanLog->record->student->name }}</td>
                                    <td><span class="badge-flag">{{ $f->code }}</span><div class="text-xs text-slate-500">{{ ScanFlag::DESCRIPTIONS[$f->code] ?? '' }}</div></td>
                                    <td class="whitespace-nowrap">{{ S::stageLabel($f->scanLog->stage) }}</td>
                                    <td class="whitespace-nowrap">
                                        {{ $f->scanLog->distance_m !== null ? number_format($f->scanLog->distance_m).' m' : '—' }}
                                        @if ($f->scanLog->accuracy !== null)<div class="text-xs text-slate-500">±{{ number_format($f->scanLog->accuracy) }} m</div>@endif
                                    </td>
                                    <td class="font-mono text-xs">{{ $f->scanLog->ip ?? '—' }}</td>
                                    <td class="whitespace-nowrap">{{ $f->scanLog->scanned_at->format('g:i:s A') }}</td>
                                    <td class="text-right whitespace-nowrap">
                                        @if ($f->isCleared())
                                            <span class="text-xs text-slate-500">Cleared by {{ $f->clearedBy?->name ?? 'teacher' }}<br>{{ $f->cleared_at->format('M j, g:i A') }}</span>
                                        @elseif (! $archived)
                                            <form method="POST" action="{{ route('flags.clear', $f) }}">@csrf<button class="btn-secondary btn-sm">Clear</button></form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="py-6 text-center text-slate-500">No flagged scans.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Settings (FR-ATT-01) --}}
        @unless ($archived)
        <form method="POST" action="{{ route('sessions.update', $session) }}" class="card h-fit space-y-4" novalidate>
            @csrf @method('PUT')
            <h2 class="h2">Session settings</h2>
            <x-field name="title" label="Title" :value="$session->title" placeholder="Optional" />
            <x-field name="grace_minutes" label="Grace period (minutes)" type="number" min="0" :value="$session->grace_minutes" required
                help="Changing this recalculates Present/Late for QR Time In scans (not manual entries)." />
            <button class="btn-primary w-full">Save</button>
        </form>
        @endunless
    </div>
</x-layouts.app>
