{{-- Student's "Today's sessions" card body; re-fetched every few seconds by the dashboard. --}}
@use('App\Models\AttendanceSession', 'S')
<h2 class="h2 mb-3 flex items-center gap-2">
    Today's sessions
    <span class="inline-flex items-center gap-1 text-xs font-normal text-slate-400" title="Updates automatically">
        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span> live
    </span>
</h2>
@if ($myTodaySessions->isEmpty())
    <p class="text-sm text-slate-500">No sessions in your classes today. When your teacher opens Time In, it shows up here.</p>
@else
    <ul class="divide-y divide-slate-100">
        @foreach ($myTodaySessions as $s)
            @php
                $open = $s->openStage();
                $mine = $myRecords->get($s->id);
                $scannedThisStage = $open && $mine && $mine->{$open.'_at'};
            @endphp
            <li class="flex items-center justify-between gap-3 py-2" data-my-session="{{ $s->id }}">
                <div class="min-w-0">
                    <div class="truncate font-medium">{{ $s->schoolClass->label() }}</div>
                    <div class="text-xs text-slate-500">
                        {{ $s->title ?: 'Attendance' }} ·
                        @if ($open)
                            <span class="font-semibold text-emerald-700">{{ S::stageLabel($open) }} open</span>
                        @elseif ($s->time_in_opened_at)
                            <span class="text-amber-700">Waiting for Time Out</span>
                        @else
                            Not started
                        @endif
                    </div>
                    {{-- The student's own status for this session --}}
                    @if ($mine && $mine->time_in_at)
                        <div class="mt-0.5 text-xs">
                            <span class="badge-{{ $mine->status }}">{{ ucfirst($mine->status) }}</span>
                            <span class="text-slate-500">Time In {{ $mine->time_in_at->format('g:i A') }}@if ($mine->time_out_at) · Time Out {{ $mine->time_out_at->format('g:i A') }}@endif</span>
                        </div>
                    @elseif ($mine && $mine->source === 'manual')
                        <div class="mt-0.5 text-xs"><span class="badge-{{ $mine->status }}">{{ ucfirst($mine->status) }}</span> <span class="text-slate-500">set by your teacher</span></div>
                    @endif
                </div>

                @if ($open && ! $scannedThisStage)
                    <a href="{{ route('scanner') }}" class="btn-primary btn-sm shrink-0">Scan {{ S::stageLabel($open) }}</a>
                @elseif ($scannedThisStage)
                    <span class="badge-approved shrink-0">✓ Scanned</span>
                @endif
            </li>
        @endforeach
    </ul>
@endif
