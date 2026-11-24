@props(['class', 'active'])

@php
    $tabs = [
        'overview' => ['Overview', route('classes.show', $class)],
        'students' => ['Students', route('classes.students', $class)],
        'schedule' => ['Schedule', route('classes.schedule', $class)], // FR-CLS-17: weekly schedule only
        'sessions' => ['Sessions', route('sessions.index', $class)],
        'announcements' => ['Announcements', route('announcements.index', $class)],
        'corrections' => ['Corrections', route('corrections.index', $class)],
        'report' => ['Report', route('reports.show', $class)],
    ];
    $pendingCorrections = $class->pendingCorrectionsCount(); // FR-ATT-18
@endphp

<div class="mb-5">
    <div class="mb-3 flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <h1 class="h1">{{ $class->subject }} <span class="font-medium text-slate-500">· {{ $class->section }}</span></h1>
        <span class="text-sm text-slate-500">{{ $class->year_level }} · {{ $class->school_term }} · {{ $class->semester }} sem</span>
        {{-- FR-CLS-17: "…" menu next to the class name (teacher pages only) --}}
        <details class="relative self-center" data-menu data-class-header-menu>
            <summary class="btn-secondary btn-sm cursor-pointer list-none px-2 [&::-webkit-details-marker]:hidden" aria-label="More actions for this class" title="More actions">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><circle cx="4" cy="10" r="1.6"/><circle cx="10" cy="10" r="1.6"/><circle cx="16" cy="10" r="1.6"/></svg>
            </summary>
            <div class="absolute left-0 z-30 mt-1 w-52 overflow-hidden rounded-lg border border-slate-200 bg-white py-1 text-left text-sm shadow-lg">
                <a href="{{ route('classes.edit', $class) }}" class="flex items-center gap-2 px-3 py-2 text-slate-700 hover:bg-slate-50">
                    <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.2 5.2l3.6 3.6M4 20h4L19 9a2.5 2.5 0 00-3.6-3.6L4.5 16.5 4 20z"/></svg>
                    Edit class details
                </a>
                <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-slate-700 hover:bg-slate-50" data-copy="#nav-class-code">
                    Copy class code
                </button>
                <span id="nav-class-code" class="hidden">{{ $class->code }}</span>
            </div>
        </details>
    </div>
    <nav class="-mx-4 flex gap-1 overflow-x-auto px-4 pb-1">
        @foreach ($tabs as $key => [$label, $url])
            <a href="{{ $url }}" class="{{ $active === $key ? 'tab-active' : 'tab' }}">
                {{ $label }}
                @if ($key === 'corrections' && $pendingCorrections)
                    <span class="ml-1 inline-grid min-w-5 place-items-center rounded-full bg-gold-400 px-1.5 text-[11px] font-bold text-brand-800" title="{{ $pendingCorrections }} pending">{{ $pendingCorrections }}</span>
                @endif
            </a>
        @endforeach
    </nav>
</div>
