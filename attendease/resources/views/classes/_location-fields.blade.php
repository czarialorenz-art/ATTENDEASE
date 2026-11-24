{{-- FR-CLS-03 / FR-CLS-17: building, floor and room (all optional). $bag: the error bag; $values: current values (or empty). --}}
@php($bagErrors = $errors->getBag($bag ?? 'default'))
<div class="grid gap-3 sm:grid-cols-3">
    @foreach (['building' => ['Building', 100, 'e.g. Main Building'], 'floor' => ['Floor', 50, 'e.g. 3rd'], 'room' => ['Room', 100, 'e.g. Tesda Lab']] as $f => [$label, $max, $ph])
        <div>
            <label for="loc_{{ $f }}" class="label">{{ $label }}</label>
            <input id="loc_{{ $f }}" name="{{ $f }}" maxlength="{{ $max }}" placeholder="{{ $ph }}"
                value="{{ $bagErrors->any() || ! isset($values) ? old($f) : ($values[$f] ?? '') }}" @class(['input', 'input-error' => $bagErrors->has($f)])>
            @if ($bagErrors->has($f))<p class="error">{{ $bagErrors->first($f) }}</p>@endif
        </div>
    @endforeach
</div>
