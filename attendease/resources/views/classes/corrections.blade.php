<x-layouts.app :title="$class->subject.' corrections'">
    <x-class-nav :class="$class" active="corrections" />

    @php
        $tabs = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'];
        $total = $counts->sum();
    @endphp

    <div class="card">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="h2">Correction requests</h2>
                <p class="text-sm text-slate-500">Students ask here when they think they were wrongly marked Absent or Late.</p>
            </div>
            <nav class="inline-flex flex-wrap rounded-lg border border-slate-200 bg-slate-50 p-0.5 text-sm" aria-label="Filter requests">
                @foreach ($tabs as $key => $label)
                    @php
                        $n = $key === 'all' ? $total : ($counts[$key] ?? 0);
                    @endphp
                    <a href="{{ route('corrections.index', [$class, 'status' => $key]) }}"
                       @class(['rounded-md px-3 py-1 font-medium', 'bg-white text-slate-900 shadow-sm' => $filter === $key, 'text-slate-500 hover:text-slate-800' => $filter !== $key])
                       @if ($filter === $key) aria-current="page" @endif>
                        {{ $label }} <span class="ml-0.5 text-xs text-slate-400">{{ $n }}</span>
                    </a>
                @endforeach
            </nav>
        </div>

        <div class="space-y-3">
            @forelse ($corrections as $c)
                @php
                    $session = $c->record->attendanceSession;
                    $bag = 'decide'.$c->id;
                @endphp
                <article @class(['rounded-xl border p-4', 'border-amber-200 bg-amber-50/40' => $c->isPending(), 'border-slate-200' => ! $c->isPending()])>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-1.5 font-semibold">
                                {{ $c->student->name }}
                                @include('classes._section-badge', ['student' => $c->student])
                            </div>
                            <div class="text-xs text-slate-500">{{ $c->student->id_number }} · requested {{ $c->created_at->format('M j, g:i A') }}</div>
                        </div>
                        <span @class(['badge-pending' => $c->status === 'pending', 'badge-approved' => $c->status === 'approved', 'badge-rejected' => $c->status === 'rejected'])>{{ ucfirst($c->status) }}</span>
                    </div>

                    <p class="mt-2 text-sm">
                        <a href="{{ route('sessions.show', $session) }}" class="font-medium text-brand-600 hover:underline">{{ $session->date->format('D, M j, Y') }}{{ $session->title ? ' · '.$session->title : '' }}</a>:
                        <span class="badge-{{ $c->current_status }}">{{ ucfirst($c->current_status) }}</span>
                        →
                        <span class="badge-{{ $c->requested_status }}">{{ ucfirst($c->requested_status) }}</span>
                        @if ($session->isArchived())<span class="badge-neutral">session archived</span>@endif
                    </p>
                    <blockquote class="mt-2 border-l-2 border-slate-300 pl-3 text-sm text-slate-700">{{ $c->reason }}</blockquote>

                    @if ($c->isPending())
                        <form method="POST" class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-start">
                            @csrf
                            <div class="min-w-0 flex-1">
                                <label for="note{{ $c->id }}" class="sr-only">Note to {{ $c->student->name }} (optional)</label>
                                <input id="note{{ $c->id }}" name="teacher_note" maxlength="1000" class="input text-sm" placeholder="Note to the student (optional)" value="{{ $errors->getBag($bag)->any() ? old('teacher_note') : '' }}">
                                @foreach ($errors->getBag($bag)->all() as $message)<p class="error">{{ $message }}</p>@endforeach
                            </div>
                            <div class="flex shrink-0 gap-2">
                                <button formaction="{{ route('corrections.approve', $c) }}" class="btn-success btn-sm"
                                    @if ($session->isArchived()) disabled title="Restore the session first" @endif>
                                    Approve → {{ ucfirst($c->requested_status) }}
                                </button>
                                <button formaction="{{ route('corrections.reject', $c) }}" class="btn-danger btn-sm">Reject</button>
                            </div>
                        </form>
                    @else
                        <p class="mt-2 text-xs text-slate-500">
                            {{ ucfirst($c->status) }} by {{ $c->decider?->name ?? 'teacher' }} on {{ $c->decided_at?->format('M j, Y g:i A') }}
                            @if ($c->teacher_note) · “{{ $c->teacher_note }}”@endif
                        </p>
                    @endif
                </article>
            @empty
                <p class="py-6 text-center text-sm text-slate-500">
                    {{ $filter === 'pending' ? 'No pending requests.' : 'No '.($filter === 'all' ? '' : $filter.' ').'requests yet.' }}
                </p>
            @endforelse
        </div>

        <div class="mt-4">{{ $corrections->links() }}</div>
    </div>
</x-layouts.app>
