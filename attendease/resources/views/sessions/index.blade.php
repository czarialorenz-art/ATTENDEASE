<x-layouts.app :title="$class->subject.' sessions'">
    <x-class-nav :class="$class" active="sessions" />

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- The archived view uses the full width (no "New session" form) so each row fits on one line. --}}
        @unless ($archived)
        <form method="POST" action="{{ route('sessions.store', $class) }}" class="card h-fit space-y-4 lg:col-span-1" novalidate>
            @csrf
            <h2 class="h2">New session</h2>

            {{-- FR-ATT-20: a new session is always for today (Manila); today's schedule is shown read-only --}}
            <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm" data-today-schedule>
                @forelse ($todaySchedules as $row)
                    <div>
                        <span class="font-medium text-slate-900">Today · {{ $today->format('l, M j') }}</span>
                        · {{ $row->timeRange() }}@if ($room = $row->room ?: $class->locationLabel()) · {{ $room }}@endif
                    </div>
                @empty
                    <div class="font-medium text-slate-900">Today · {{ $today->format('l, M j') }}</div>
                @endforelse
            </div>

            @if ($todaySchedules->isEmpty() && ! $todayProblem)
                <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900" role="status" data-no-schedule>
                    This class isn't scheduled today. You can still create a session for a make-up class.
                </p>
            @endif

            @if ($todaySession && ! $todayProblem)
                <p class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-900" role="status" data-today-exists>
                    A session for today already exists. <a href="{{ route('sessions.show', $todaySession) }}" class="font-semibold underline">Open it →</a>
                </p>
            @endif

            {{-- FR-ATT-19: today outside the semester is blocked (also on the server), with a link to fix the dates --}}
            @if ($todayProblem || $errors->has('date'))
                <div id="semester-warning" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900" role="alert">
                    <p>{{ $errors->first('date') ?: $todayProblem.'; it wouldn\'t count in semester totals. Update the semester dates first.' }}</p>
                    <a href="{{ route('classes.edit', $class) }}" class="mt-1 inline-block font-semibold underline">Fix semester dates →</a>
                </div>
            @endif
            <p class="text-xs text-slate-500">Semester: {{ $class->semester_start->format('M j, Y') }} – {{ $class->semester_end->format('M j, Y') }}. To use another date, edit the session after creating it.</p>
            <x-field name="title" label="Title" placeholder="Optional, e.g. Week 5 lecture" />
            <x-field name="grace_minutes" label="Grace period (minutes)" type="number" :value="15" min="0" help="Scans within this time after Time In opens are Present; later ones are Late." />
            <button class="btn-primary w-full disabled:cursor-not-allowed disabled:opacity-50" @disabled($todayProblem)>Create session</button>
        </form>
        @endunless

        <div @class(['card', 'lg:col-span-2' => ! $archived, 'lg:col-span-3' => $archived])>
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <h2 class="h2">{{ $archived ? 'Archived sessions' : 'Sessions' }}</h2>
                {{-- Active / Archived switch (FR-ATT-16) --}}
                <nav class="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5 text-sm" aria-label="Show sessions">
                    {{-- The search carries over when switching views. --}}
                    <a href="{{ route('sessions.index', [$class, ...($search !== '' ? ['q' => $search] : [])]) }}" @class(['rounded-md px-3 py-1 font-medium', 'bg-white text-slate-900 shadow-sm' => ! $archived, 'text-slate-500 hover:text-slate-800' => $archived]) @if (! $archived) aria-current="page" @endif>Active</a>
                    <a href="{{ route('sessions.index', [$class, 'archived' => 1, ...($search !== '' ? ['q' => $search] : [])]) }}" @class(['rounded-md px-3 py-1 font-medium', 'bg-white text-slate-900 shadow-sm' => $archived, 'text-slate-500 hover:text-slate-800' => ! $archived]) @if ($archived) aria-current="page" @endif>
                        Archived @if ($archivedCount)<span class="ml-0.5 text-xs text-slate-400">{{ $archivedCount }}</span>@endif
                    </a>
                </nav>
            </div>

            {{-- Search (title or date) --}}
            <form method="GET" action="{{ route('sessions.index', $class) }}" class="mb-3 flex gap-2" role="search">
                @if ($archived)<input type="hidden" name="archived" value="1">@endif
                <div class="relative min-w-0 flex-1">
                    <svg class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M20 20l-3.5-3.5"/></svg>
                    <label for="session-search" class="sr-only">Search {{ $archived ? 'archived sessions' : 'sessions' }}</label>
                    <input id="session-search" type="search" name="q" value="{{ $search }}" maxlength="100" autocomplete="off"
                        placeholder="Search {{ $archived ? 'archived sessions' : 'sessions' }} by title or date, e.g. week 2, Nov 13, 2026-11"
                        class="input pl-9">
                </div>
                <button class="btn-secondary shrink-0">Search</button>
                @if ($search !== '')
                    <a href="{{ route('sessions.index', [$class, ...($archived ? ['archived' => 1] : [])]) }}" class="btn-secondary shrink-0">Clear</a>
                @endif
            </form>
            @if ($search !== '')
                <p class="mb-2 text-sm text-slate-500">
                    {{ $sessions->total() }} {{ \Illuminate\Support\Str::plural('result', $sessions->total()) }} for “<span class="font-medium text-slate-700">{{ $search }}</span>”
                </p>
            @endif

            @if ($archived)
                <p class="mb-3 truncate rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900"
                   title="Archived sessions don't count in reports or students' records. Each one is deleted for good, with its attendance and scans, one year after it was archived unless you restore it.">
                    Not counted in reports · <strong>deleted for good 1 year after archiving</strong> unless restored.
                </p>
            @endif

            {{-- Active: extra bottom room so the last row's Edit menu isn't cut off while the table scrolls on phones.
                 Archived: no dropdown, so the table simply scrolls inside the card instead of spilling past its edge. --}}
            <div @class(['table-wrap', 'pb-24 md:overflow-visible md:pb-0' => ! $archived])>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th><th>Title</th><th>Grace</th><th>Status</th><th>Records</th>
                            @if ($archived)<th>Deletes on</th>@endif
                            <th><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($sessions as $s)
                            <tr>
                                <td class="whitespace-nowrap">{{ $s->date->format('D, M j, Y') }}</td>
                                <td @class(['whitespace-nowrap' => $archived])>{{ $s->title ?: '—' }}</td>
                                <td class="whitespace-nowrap">{{ $s->grace_minutes }} min</td>
                                <td class="whitespace-nowrap">
                                    @if ($s->openStage()) <span class="badge-approved">{{ \App\Models\AttendanceSession::stageLabel($s->openStage()) }} open</span>
                                    @elseif ($s->time_out_closed_at) <span class="badge-neutral">Completed</span>
                                    @elseif ($s->time_in_opened_at) <span class="badge-pending">In progress</span>
                                    @else <span class="text-slate-400">Not started</span> @endif
                                    @if ($problem = $class->semesterProblem($s->date))
                                        <span class="badge-flag ml-1" title="{{ $problem }}; it doesn't count in semester totals.">Outside semester</span>
                                    @endif
                                </td>
                                <td>{{ $s->records_count }}</td>

                                @if ($archived)
                                    <td class="whitespace-nowrap" title="Archived {{ $s->archived_at->format('M j, Y') }}">
                                        {{ $s->deletesOn()->format('M j, Y') }}
                                    </td>
                                    <td class="text-right whitespace-nowrap">
                                        <div class="flex justify-end gap-2">
                                            <a href="{{ route('sessions.show', $s) }}" class="btn-secondary btn-sm">View</a>
                                            <form method="POST" action="{{ route('sessions.restore', $s) }}">@csrf<button class="btn-primary btn-sm">Restore</button></form>
                                            <form method="POST" action="{{ route('sessions.destroy', $s) }}"
                                                  data-confirm="Delete the {{ $s->date->format('M j, Y') }} session for good? Its attendance records and scans will be deleted too. This can't be undone.">
                                                @csrf @method('DELETE')
                                                <button class="btn-danger btn-sm">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                @else
                                    <td class="text-right whitespace-nowrap">
                                        <div class="flex items-center justify-end gap-2">
                                            <a href="{{ route('sessions.show', $s) }}" class="btn-secondary btn-sm">Open</a>

                                            {{-- Edit menu: Edit or Archive --}}
                                            <details class="relative" data-menu>
                                                <summary class="btn-secondary btn-sm cursor-pointer list-none [&::-webkit-details-marker]:hidden" aria-label="More actions for {{ $s->date->format('M j') }}">
                                                    Edit
                                                    <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.3 7.3a1 1 0 011.4 0L10 10.6l3.3-3.3a1 1 0 111.4 1.4l-4 4a1 1 0 01-1.4 0l-4-4a1 1 0 010-1.4z"/></svg>
                                                </summary>
                                                <div class="absolute right-0 z-20 mt-1 w-44 overflow-hidden rounded-lg border border-slate-200 bg-white py-1 text-left text-sm shadow-lg">
                                                    <a href="{{ route('sessions.edit', $s) }}" class="flex items-center gap-2 px-3 py-2 text-slate-700 hover:bg-slate-50">
                                                        <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.2 5.2l3.6 3.6M4 20h4L19 9a2.5 2.5 0 00-3.6-3.6L4.5 16.5 4 20z"/></svg>
                                                        Edit session
                                                    </a>
                                                    @if ($s->openStage())
                                                        <span class="flex cursor-not-allowed items-center gap-2 px-3 py-2 text-slate-400" title="Close the open stage first">
                                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M5 7l1 12a2 2 0 002 2h8a2 2 0 002-2l1-12M9 7V4h6v3"/></svg>
                                                            Archive (stage open)
                                                        </span>
                                                    @else
                                                        <form method="POST" action="{{ route('sessions.archive', $s) }}"
                                                              data-confirm="Archive the {{ $s->date->format('M j, Y') }} session? It will be hidden from reports and deleted for good on {{ now()->addDays(\App\Models\AttendanceSession::KEEP_ARCHIVED_DAYS)->format('M j, Y') }} unless you restore it.">
                                                            @csrf
                                                            <button class="flex w-full items-center gap-2 px-3 py-2 text-left text-red-600 hover:bg-red-50">
                                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M5 7l1 12a2 2 0 002 2h8a2 2 0 002-2l1-12M9 7V4h6v3"/></svg>
                                                                Archive
                                                            </button>
                                                        </form>
                                                    @endif
                                                </div>
                                            </details>
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ $archived ? 7 : 6 }}" class="py-6 text-center text-slate-500">
                                @if ($search !== '')
                                    No {{ $archived ? 'archived ' : '' }}sessions match “{{ $search }}”.
                                @else
                                    {{ $archived ? 'No archived sessions.' : 'No sessions yet.' }}
                                @endif
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $sessions->links() }}</div>
        </div>
    </div>
</x-layouts.app>
