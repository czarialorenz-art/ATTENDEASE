{{-- Shared by "post" and "edit". $a is the announcement being edited, or null. --}}
@php
    $a = $announcement ?? null;
    $type = old('type', $a?->type ?? \App\Models\Announcement::GENERAL);
    $isCancel = $type === \App\Models\Announcement::CANCELLATION;
@endphp
<fieldset>
    <legend class="label">Type</legend>
    <div class="grid gap-2 sm:grid-cols-2">
        @foreach (\App\Models\Announcement::TYPES as $value => $label)
            <label class="cursor-pointer">
                <input type="radio" name="type" value="{{ $value }}" class="peer sr-only" @checked($type === $value) data-announcement-type>
                <span class="flex items-center gap-2 rounded-lg border border-slate-300 bg-white p-3 text-sm peer-checked:border-brand-600 peer-checked:ring-2 peer-checked:ring-brand-100 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-600">
                    @if ($value === 'cancellation')
                        <svg class="h-5 w-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path stroke-linecap="round" d="M3 10h18M8 3v4M16 3v4M9.5 13.5l5 5M14.5 13.5l-5 5"/></svg>
                    @else
                        <svg class="h-5 w-5 text-brand-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 10v4a1 1 0 001 1h2l5 4V5L7 9H5a1 1 0 00-1 1zM16 9a4 4 0 010 6"/></svg>
                    @endif
                    <span class="font-semibold">{{ $label }}</span>
                </span>
            </label>
        @endforeach
    </div>
    @error('type')<p class="error">{{ $message }}</p>@enderror
</fieldset>

<div data-cancel-only @class(['hidden' => ! $isCancel])>
    <x-field name="cancelled_date" label="Date of the cancelled class" type="date" :value="$a?->cancelled_date?->toDateString()"
        :min="now()->toDateString()" help="Students see this date on their dashboard and class page." />
</div>

<x-field name="title" label="Title" :value="$a?->title" maxlength="120"
    :placeholder="$isCancel ? 'Class cancelled (default)' : 'e.g. Bring your laptops on Monday'" data-title />
<x-field name="body" label="Message" type="textarea" rows="4" maxlength="2000" :value="$a?->body"
    :placeholder="$isCancel ? 'Optional: the reason, e.g. Faculty meeting. Make-up class on Friday.' : 'What do your students need to know?'" data-body />

@once
@push('scripts')
<script>
    // Show the date field only for cancellations, and adjust the hints.
    document.querySelectorAll('[data-announcement-type]').forEach((radio) => radio.addEventListener('change', () => {
        const form = radio.form;
        const cancel = form.querySelector('[data-announcement-type]:checked').value === 'cancellation';
        form.querySelector('[data-cancel-only]').classList.toggle('hidden', !cancel);
        form.querySelector('[data-title]').placeholder = cancel ? 'Class cancelled (default)' : 'e.g. Bring your laptops on Monday';
        form.querySelector('[data-body]').placeholder = cancel
            ? 'Optional: the reason, e.g. Faculty meeting. Make-up class on Friday.'
            : 'What do your students need to know?';
    }));
</script>
@endpush
@endonce
