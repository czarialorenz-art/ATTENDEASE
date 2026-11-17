@php
    $isTeacher = auth()->user()->isTeacher();
    $isStudent = auth()->user()->isStudent();
@endphp
<x-layouts.app title="Dashboard">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="h1">Hi, {{ \Illuminate\Support\Str::before(auth()->user()->name, ' ') ?: auth()->user()->name }}</h1>
            <p class="text-sm text-slate-500">{{ now()->format('l, F j, Y') }}</p>
        </div>
        @if ($isTeacher)
            <a href="{{ route('classes.create') }}" class="btn-primary">+ Create a class</a>
        @endif
    </div>

    {{-- Join a class (FR-CLS-04): Students only (FR-ACL-03) --}}
    @if ($isStudent)
    <form method="POST" action="{{ route('classes.join') }}" class="card mb-6 flex flex-col gap-3 sm:flex-row sm:items-end" novalidate>
        @csrf
        <div class="flex-1">
            <label for="code" class="label">Join a class</label>
            <input id="code" name="code" value="{{ old('code') }}" maxlength="8" autocapitalize="characters" autocomplete="off"
                placeholder="Enter class code" @class(['input font-mono tracking-widest uppercase', 'input-error' => $errors->has('code')])>
            @error('code')<p class="error">{{ $message }}</p>@enderror
        </div>
        <button class="btn-primary sm:w-40">Ask to join</button>
    </form>
    @endif

    {{-- FR-ACL-03: a Teacher sees only the teaching side, a Student only the student side; each fills the width. --}}
    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Teaching side --}}
        @if ($isTeacher)
        <section class="grid items-start gap-6 lg:col-span-2 lg:grid-cols-2">
            <div class="card">
                <h2 class="h2 mb-3">Classes I teach</h2>
                @forelse ($teaching as $c)
                    <a href="{{ route('classes.show', $c) }}" class="-mx-2 flex items-center justify-between gap-3 rounded-lg px-2 py-2.5 hover:bg-slate-50">
                        <div class="min-w-0">
                            <div class="truncate font-medium">{{ $c->subject }} · {{ $c->section }}</div>
                            <div class="text-xs text-slate-500">{{ $c->school_term }} · {{ $c->semester }} sem · {{ $c->student_count }} {{ \Illuminate\Support\Str::plural('student', $c->student_count) }} · code <span class="font-mono">{{ $c->code }}</span></div>
                        </div>
                        <span class="flex shrink-0 flex-wrap justify-end gap-1">
                            @if ($c->pending_count)
                                <span class="badge-pending">{{ $c->pending_count }} pending</span>
                            @endif
                            @if ($c->corrections_pending)
                                <span class="badge-flag">{{ $c->corrections_pending }} {{ \Illuminate\Support\Str::plural('correction', $c->corrections_pending) }}</span>
                            @endif
                        </span>
                    </a>
                @empty
                    <p class="text-sm text-slate-500">You don't teach any classes yet. Create one to get a join code for your students.</p>
                @endforelse
            </div>

            {{-- Today's sessions: unfinished sessions only, refreshed automatically --}}
            @if ($teaching->isNotEmpty())
                <div class="card" id="today-sessions" data-refresh-url="{{ route('dashboard.today') }}" aria-live="polite">
                    @include('dashboard._today')
                </div>
            @endif
        </section>

        @endif

        {{-- Student side --}}
        @if ($isStudent)
        {{-- Scanning is from the top bar's Scan QR button. --}}
        <section class="grid items-start gap-6 lg:col-span-2 lg:grid-cols-2">
            <div class="card">
                <h2 class="h2 mb-3">Classes I'm in</h2>
                @forelse ($enrolled as $e)
                    @php($approved = $e->status === 'approved')
                    <{{ $approved ? 'a' : 'div' }} @if ($approved) href="{{ route('classes.show', $e->schoolClass) }}" @endif
                        @class(['-mx-2 flex items-center justify-between gap-3 rounded-lg px-2 py-2.5', 'hover:bg-slate-50' => $approved])>
                        <div class="min-w-0">
                            <div class="truncate font-medium">{{ $e->schoolClass->subject }} · {{ $e->schoolClass->section }}</div>
                            <div class="text-xs text-slate-500">{{ $e->schoolClass->teacher->name }} · {{ $e->schoolClass->school_term }}</div>
                        </div>
                        @unless ($approved)<span class="badge-pending shrink-0">Waiting for approval</span>@endunless
                    </{{ $approved ? 'a' : 'div' }}>
                @empty
                    <p class="text-sm text-slate-500">You haven't joined any classes. Enter the code your teacher gives you above.</p>
                @endforelse
            </div>

            {{-- Student's Today's sessions: unfinished sessions in my classes, refreshed automatically --}}
            @if ($enrolled->where('status', 'approved')->isNotEmpty())
                <div class="card" id="my-today-sessions" data-refresh-url="{{ route('dashboard.today.student') }}" aria-live="polite">
                    @include('dashboard._today_student')
                </div>

                {{-- FR-ANN-03: upcoming cancellations, then the last week's announcements --}}
                <div class="card" id="my-announcements">
                    <h2 class="h2 mb-3">Announcements</h2>
                    @forelse ($myAnnouncements as $a)
                        @php($hot = $a->isUpcomingCancellation())
                        <a href="{{ route('classes.show', $a->class_id) }}#announcements"
                           @class(['-mx-2 block rounded-lg px-2 py-2.5', 'bg-red-50 hover:bg-red-100' => $hot, 'hover:bg-slate-50' => ! $hot])>
                            <div class="flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
                                <span class="font-medium text-slate-700">{{ $a->schoolClass->label() }}</span>
                                @if ($hot)<span class="badge-absent">Class cancelled</span>@endif
                                <span>· {{ $a->created_at->diffForHumans() }}</span>
                            </div>
                            <div class="font-medium text-slate-900">
                                @if ($a->isCancellation())
                                    No class on {{ $a->cancelled_date->format('D, M j') }}@if ($a->cancelled_date->isToday()) (today)@elseif ($a->cancelled_date->isTomorrow()) (tomorrow)@endif
                                @else
                                    {{ $a->title }}
                                @endif
                            </div>
                            @if ($a->body)<div class="line-clamp-2 text-sm text-slate-600">{{ $a->body }}</div>@endif
                        </a>
                    @empty
                        <p class="text-sm text-slate-500">No new announcements from your teachers this week.</p>
                    @endforelse
                </div>
            @endif
        </section>
        @endif
    </div>
    @push('scripts')
    <script>
        // Keep both "Today's sessions" cards (teacher and student) current:
        // new sessions appear and completed ones drop off.
        (() => {
            const cards = [...document.querySelectorAll('[data-refresh-url]')];
            if (!cards.length) return;
            const busy = new WeakSet();

            async function refresh(card) {
                if (busy.has(card) || document.hidden) return;
                busy.add(card);
                try {
                    const res = await fetch(card.dataset.refreshUrl, { headers: { Accept: 'text/html' }, cache: 'no-store' });
                    if (res.ok) {
                        const html = await res.text();
                        if (html.trim() !== card.innerHTML.trim()) card.innerHTML = html;
                    }
                } catch (e) { /* offline for a moment: try again next time */ }
                finally { busy.delete(card); }
            }

            const refreshAll = () => cards.forEach(refresh);
            setInterval(refreshAll, 15000);
            document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshAll(); });
        })();
    </script>
    @endpush
</x-layouts.app>
