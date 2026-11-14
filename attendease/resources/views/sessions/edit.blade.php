<x-layouts.app :title="'Edit session '.$session->date->format('M j')">
    <x-class-nav :class="$class" active="sessions" />

    <div class="mx-auto max-w-xl">
        <form method="POST" action="{{ route('sessions.update', $session) }}" class="card space-y-4" novalidate>
            @csrf @method('PUT')
            <input type="hidden" name="from_edit" value="1">
            <h2 class="h2">Edit session</h2>

            @if ($session->hasStarted())
                <div>
                    <span class="label">Date</span>
                    <p class="input bg-slate-50 text-slate-600">{{ $session->date->format('l, F j, Y') }}</p>
                    <p class="help">The date can't change after Time In has been opened.</p>
                </div>
            @else
                <x-field name="date" label="Date" type="date" :value="$session->date->toDateString()" required help="Stages can only be opened on this date." />
            @endif

            <x-field name="title" label="Title" :value="$session->title" placeholder="Optional, e.g. Week 5 lecture" />
            <x-field name="grace_minutes" label="Grace period (minutes)" type="number" min="0" :value="$session->grace_minutes" required
                help="Changing this recalculates Present/Late for QR Time In scans (not manual entries)." />

            <div class="flex justify-end gap-2">
                <a href="{{ route('sessions.index', $class) }}" class="btn-secondary">Cancel</a>
                <button class="btn-primary">Save changes</button>
            </div>
        </form>
    </div>
</x-layouts.app>
