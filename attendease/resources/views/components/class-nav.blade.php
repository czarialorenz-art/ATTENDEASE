@props(['class', 'active'])

@php
    $tabs = [
        'overview' => ['Overview', route('classes.show', $class)],
        'students' => ['Students', route('classes.students', $class)],
        'schedule' => ['Schedule & location', route('classes.edit', $class)],
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
