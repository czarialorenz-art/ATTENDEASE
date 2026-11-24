{{-- FR-CLS-17: the Schedule tab holds only the weekly schedule. Details and location are under the class's "…" menu. --}}
<x-layouts.app :title="$class->subject.' schedule'">
    <x-class-nav :class="$class" active="schedule" />

    @php
        $hm = fn ($t) => substr((string) $t, 0, 5);
        $addBag = $errors->getBag('addSchedule');
    @endphp

    <div class="max-w-3xl">
        {{-- FR-CLS-12: weekly schedules, added, edited and removed one at a time --}}
        <section class="card space-y-4" id="schedules">
            <div class="flex items-baseline justify-between gap-2">
                <h2 class="h2">Weekly schedule</h2>
                <span class="text-sm text-slate-500">{{ $class->schedules->count() }} {{ \Illuminate\Support\Str::plural('schedule', $class->schedules->count()) }}</span>
            </div>

            @if ($class->schedules->isEmpty())
                <p class="rounded-lg border border-dashed border-slate-300 px-4 py-6 text-center text-sm text-slate-500">
                    No schedules yet. Add the days and times this class meets below.
                </p>
            @else
                <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200" data-schedule-list>
                    @foreach ($class->schedules as $s)
                        @php($bag = 'schedule'.$s->id)
                        <li class="px-4 py-3" data-schedule="{{ $s->id }}">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <div class="font-semibold">{{ $s->dayName() }}</div>
                                    <div class="text-sm text-slate-600">{{ $s->timeRange() }} <span class="text-slate-400">· {{ $s->durationLabel() }}</span></div>
                                </div>
                                <div class="flex gap-2">
                                    <button type="button" class="btn-secondary btn-sm" data-edit-toggle="{{ $s->id }}"
                                        aria-expanded="{{ $errors->getBag($bag)->any() ? 'true' : 'false' }}" aria-controls="edit-schedule-{{ $s->id }}">Edit</button>
                                    <form method="POST" action="{{ route('schedules.destroy', $s) }}"
                                          data-confirm="Remove the {{ $s->dayName() }} {{ $s->timeRange() }} schedule? Sessions already created are kept.">
                                        @csrf @method('DELETE')
                                        <button class="btn-secondary btn-sm text-red-600">Remove</button>
                                    </form>
                                </div>
                            </div>

                            {{-- Edit in place; opens by itself if this form has errors --}}
                            <form method="POST" action="{{ route('schedules.update', $s) }}" id="edit-schedule-{{ $s->id }}"
                                  @class(['mt-3 space-y-3 rounded-lg bg-slate-50 p-3', 'hidden' => ! $errors->getBag($bag)->any()]) novalidate>
                                @csrf @method('PUT')
                                @include('classes._schedule-fields', [
                                    'key' => 'edit'.$s->id, 'bag' => $bag,
                                    'values' => ['day_of_week' => $s->day_of_week, 'start_time' => $hm($s->start_time), 'end_time' => $hm($s->end_time)],
                                ])
                                <div class="flex justify-end gap-2">
                                    <button type="button" class="btn-secondary btn-sm" data-edit-toggle="{{ $s->id }}">Cancel</button>
                                    <button class="btn-primary btn-sm">Save changes</button>
                                </div>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif

            <form method="POST" action="{{ route('schedules.store', $class) }}" class="space-y-3 border-t border-slate-100 pt-4" novalidate>
                @csrf
                <h3 class="font-semibold">Add a schedule</h3>
                @include('classes._schedule-fields', ['key' => 'add', 'bag' => 'addSchedule', 'values' => []])
                <div class="flex justify-end"><button class="btn-primary">+ Add schedule</button></div>
                <p class="help">A schedule can't overlap another schedule of this class or of your other classes this semester.</p>
            </form>
        </section>
    </div>
    @push('scripts')
    <script>
        // Edit / Cancel open and close a schedule's edit form in place.
        document.querySelectorAll('[data-edit-toggle]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const id = btn.dataset.editToggle;
                const form = document.getElementById('edit-schedule-' + id);
                const open = form.classList.toggle('hidden') === false;
                document.querySelectorAll(`[data-edit-toggle="${id}"][aria-controls]`).forEach((b) => b.setAttribute('aria-expanded', open));
                if (open) form.querySelector('select')?.focus();
            });
        });
    </script>
    @endpush
</x-layouts.app>
