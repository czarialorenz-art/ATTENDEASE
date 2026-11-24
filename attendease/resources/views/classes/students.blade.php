@use('App\Http\Controllers\EnrollmentController')
<x-layouts.app :title="$class->subject.' students'">
    <x-class-nav :class="$class" active="students" />

    @php
        // "3 of 10" while filtering, otherwise just the count.
        $count = fn ($shown, $status) => $filtering ? $shown->count().' of '.($totals[$status] ?? 0) : $shown->count();
        $noMatch = 'No students match your search.';
    @endphp

    {{-- FR-CLS-10: search by name and filter by section --}}
    <form method="GET" action="{{ route('classes.students', $class) }}" class="card mb-6 flex flex-col gap-3 sm:flex-row sm:items-end" role="search" data-auto-filter>
        <div class="min-w-0 flex-1">
            <label for="student-search" class="label">Search</label>
            <div class="relative">
                <svg class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M20 20l-3.5-3.5"/></svg>
                <input id="student-search" type="search" name="q" value="{{ $search }}" maxlength="100" autocomplete="off"
                    placeholder="Student name, number or email" class="input pl-9">
            </div>
        </div>
        <div class="sm:w-56">
            <label for="student-section" class="label">Section</label>
            <select id="student-section" name="section" class="input">
                <option value="">All sections</option>
                @foreach ($sections as $s)
                    <option value="{{ $s }}" @selected(strcasecmp($section, $s) === 0)>{{ $s }}</option>
                @endforeach
                @if ($hasNoSection)
                    <option value="{{ EnrollmentController::NO_SECTION }}" @selected($section === EnrollmentController::NO_SECTION)>No section</option>
                @endif
            </select>
        </div>
        <div class="flex gap-2">
            <button class="btn-primary">Search</button>
            @if ($filtering)
                <a href="{{ route('classes.students', $class) }}" class="btn-secondary">Clear</a>
            @endif
        </div>
    </form>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="card">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="h2">Join requests <span class="badge-pending ml-1">{{ $count($pending, 'pending') }}</span></h2>
                {{-- FR-CLS-13: approve every request shown (all of them, or the ones the filter shows), from 1 request up --}}
                @if ($pending->isNotEmpty())
                    @php($n = $pending->count())
                    <form method="POST" action="{{ route('enrollments.approve-all', $class) }}"
                          data-confirm="Approve {{ $filtering ? 'the '.$n.' '.\Illuminate\Support\Str::plural('request', $n).' shown' : 'all '.$n.' join '.\Illuminate\Support\Str::plural('request', $n) }}? Each student will be notified." class="ml-auto">
                        @csrf
                        @foreach ($pending as $e)<input type="hidden" name="ids[]" value="{{ $e->id }}">@endforeach
                        <button class="btn-success btn-sm">{{ $filtering ? 'Approve all '.$n.' shown' : 'Approve all ('.$n.')' }}</button>
                    </form>
                @endif
            </div>
            @forelse ($pending as $e)
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 py-2.5 first:border-0">
                    <div>
                        <div class="flex flex-wrap items-center gap-1.5 font-medium">
                            {{ $e->student->listName() }}
                            @include('classes._section-badge', ['student' => $e->student])
                        </div>
                        <div class="text-xs text-slate-500">{{ $e->student->id_number }}{{ $e->student->email ? ' · '.$e->student->email : '' }} · {{ $e->updated_at->diffForHumans() }}</div>
                    </div>
                    <div class="flex gap-2">
                        <form method="POST" action="{{ route('enrollments.approve', $e) }}">@csrf<button class="btn-success btn-sm">Approve</button></form>
                        <form method="POST" action="{{ route('enrollments.reject', $e) }}">@csrf<button class="btn-secondary btn-sm">Reject</button></form>
                    </div>
                </div>
            @empty
                <p class="text-sm text-slate-500">
                    @if ($filtering && ($totals['pending'] ?? 0) > 0) {{ $noMatch }}
                    @else No pending requests. Share the code <span class="font-mono font-semibold">{{ $class->code }}</span> with your students.
                    @endif
                </p>
            @endforelse
        </section>

        <section class="card">
            <h2 class="h2 mb-3">Enrolled students <span class="badge-neutral ml-1">{{ $count($approved, 'approved') }}</span></h2>
            @forelse ($approved as $e)
                <div class="flex items-center justify-between gap-2 border-t border-slate-100 py-2.5 first:border-0">
                    <div>
                        <div class="flex flex-wrap items-center gap-1.5 font-medium">
                            {{ $e->student->listName() }}
                            @include('classes._section-badge', ['student' => $e->student])
                            {{-- FR-CLS-15: semester attendance rate (green 90%+, amber 75–89%, red below 75%) --}}
                            @php($rate = $rates[$e->student->id] ?? null)
                            @if ($rate === null)
                                <span class="badge-neutral" title="No sessions held yet this semester" data-rate>—</span>
                            @else
                                <span class="{{ $rate >= 90 ? 'badge-present' : ($rate >= 75 ? 'badge-late' : 'badge-absent') }}" title="Attendance rate this semester" data-rate>{{ rtrim(rtrim(number_format($rate, 1), '0'), '.') }}%</span>
                            @endif
                        </div>
                        <div class="text-xs text-slate-500">{{ $e->student->id_number }}{{ $e->student->email ? ' · '.$e->student->email : '' }}</div>
                    </div>
                    {{-- FR-CLS-15: "…" more actions, same dropdown as the Sessions page --}}
                    <details class="relative shrink-0" data-menu>
                        <summary class="btn-secondary btn-sm cursor-pointer list-none px-2 [&::-webkit-details-marker]:hidden" aria-label="More actions for {{ $e->student->name }}" title="More actions">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><circle cx="4" cy="10" r="1.6"/><circle cx="10" cy="10" r="1.6"/><circle cx="16" cy="10" r="1.6"/></svg>
                        </summary>
                        <div class="absolute right-0 z-20 mt-1 w-48 overflow-hidden rounded-lg border border-slate-200 bg-white py-1 text-left text-sm shadow-lg">
                            <a href="{{ route('classes.student-attendance', [$class, $e->student]) }}" class="flex items-center gap-2 px-3 py-2 text-slate-700 hover:bg-slate-50">
                                <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3M4 11h16M5 5h14a1 1 0 011 1v13a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z"/></svg>
                                View attendance
                            </a>
                            <form method="POST" action="{{ route('enrollments.remove', $e) }}" data-confirm="Remove {{ $e->student->name }} from this class? Their past records are kept.">
                                @csrf
                                <button class="flex w-full items-center gap-2 px-3 py-2 text-left text-red-600 hover:bg-red-50">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M5 7l1 12a2 2 0 002 2h8a2 2 0 002-2l1-12M9 7V4h6v3"/></svg>
                                    Remove from class
                                </button>
                            </form>
                        </div>
                    </details>
                </div>
            @empty
                <p class="text-sm text-slate-500">{{ $filtering && ($totals['approved'] ?? 0) > 0 ? $noMatch : 'No students yet.' }}</p>
            @endforelse

            @if ($removed->isNotEmpty())
                <h3 class="mt-5 mb-1 text-sm font-semibold text-slate-600">Removed</h3>
                @foreach ($removed as $e)
                    <div class="text-sm text-slate-500">{{ $e->student->listName() }} · {{ $e->student->id_number }}{{ ($code = $e->student->sectionCode()) ? ' · '.$code : '' }}</div>
                @endforeach
            @endif
        </section>
    </div>

    @push('scripts')
    <script>
        // Choosing a section applies it straight away.
        document.querySelector('[data-auto-filter] select[name="section"]')
            ?.addEventListener('change', (e) => e.target.form.submit());
    </script>
    @endpush
</x-layouts.app>
