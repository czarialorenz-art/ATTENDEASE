{{-- "Today's sessions" card body; re-fetched every few seconds by the dashboard. --}}
@use('App\Models\AttendanceSession', 'S')
<h2 class="h2 mb-3 flex items-center gap-2">
    Today's sessions
    <span class="inline-flex items-center gap-1 text-xs font-normal text-slate-400" title="Updates automatically">
        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span> live
    </span>
</h2>
@if ($todaySessions->isEmpty())
    <p class="text-sm text-slate-500">No sessions are running today. Create one from a class's <strong>Sessions</strong> tab.</p>
@else
    <ul class="divide-y divide-slate-100">
        @foreach ($todaySessions as $s)
            @php($open = $s->openStage())
            <li class="flex items-center justify-between gap-3 py-2" data-today-session="{{ $s->id }}">
                <div class="min-w-0">
                    <div class="truncate font-medium">{{ $s->schoolClass->label() }}</div>
                    <div class="text-xs text-slate-500">{{ $s->title ?: 'Attendance' }} ·
                        @if ($open)
                            <span class="font-semibold text-emerald-700">{{ S::stageLabel($open) }} open</span>
                        @elseif ($s->time_in_opened_at)
                            <span class="text-amber-700">In progress</span>
                        @else
                            Not started
                        @endif
                    </div>
                </div>
                <a href="{{ $open ? route('sessions.live', $s) : route('sessions.show', $s) }}" @class(['btn-sm shrink-0', 'btn-primary' => $open, 'btn-secondary' => ! $open])>
                    {{ $open ? 'Show QR' : 'Open' }}
                </a>
            </li>
        @endforeach
    </ul>
@endif
