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
                    <option value="{{ $s }}" @selected(strcasecmp($section, $s) === 0)>Section {{ $s }}</option>
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
            <h2 class="h2 mb-3">Join requests <span class="badge-pending ml-1">{{ $count($pending, 'pending') }}</span></h2>
            @forelse ($pending as $e)
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 py-2.5 first:border-0">
                    <div>
                        <div class="flex flex-wrap items-center gap-1.5 font-medium">
                            {{ $e->student->name }}
                            @include('classes._section-badge', ['student' => $e->student])
                        </div>
                        <div class="text-xs text-slate-500">{{ $e->student->id_number }} · {{ $e->student->email }} · {{ $e->updated_at->diffForHumans() }}</div>
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
                            {{ $e->student->name }}
                            @include('classes._section-badge', ['student' => $e->student])
                        </div>
                        <div class="text-xs text-slate-500">{{ $e->student->id_number }} · {{ $e->student->email }}</div>
                    </div>
                    <form method="POST" action="{{ route('enrollments.remove', $e) }}" data-confirm="Remove {{ $e->student->name }} from this class? Their past records are kept.">
                        @csrf<button class="btn-secondary btn-sm text-red-600">Remove</button>
                    </form>
                </div>
            @empty
                <p class="text-sm text-slate-500">{{ $filtering && ($totals['approved'] ?? 0) > 0 ? $noMatch : 'No students yet.' }}</p>
            @endforelse

            @if ($removed->isNotEmpty())
                <h3 class="mt-5 mb-1 text-sm font-semibold text-slate-600">Removed</h3>
                @foreach ($removed as $e)
                    <div class="text-sm text-slate-500">{{ $e->student->name }} · {{ $e->student->id_number }}{{ filled($e->student->section) ? ' · Section '.$e->student->section : '' }}</div>
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
