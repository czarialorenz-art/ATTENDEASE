{{--
    Day / start / end fields for one schedule (FR-CLS-12).
    $key:    unique id prefix for the inputs
    $bag:    the error bag of this form (each form shows only its own errors)
    $values: ['day_of_week' => ..., 'start_time' => 'HH:MM', 'end_time' => 'HH:MM']
--}}
@php
    $errs = $errors->getBag($bag);
    $val = fn ($f) => $errs->any() ? old($f) : ($values[$f] ?? '');
@endphp
<div class="grid grid-cols-2 gap-3 sm:grid-cols-[1.4fr_1fr_1fr]">
    <div class="col-span-2 sm:col-span-1">
        <label for="{{ $key }}_day" class="label">Day <span class="text-red-600">*</span></label>
        <select id="{{ $key }}_day" name="day_of_week" @class(['input', 'input-error' => $errs->has('day_of_week')]) required>
            <option value="">Choose a day…</option>
            @foreach (\App\Models\ClassSchedule::DAYS as $d => $name)
                <option value="{{ $d }}" @selected((string) $val('day_of_week') === (string) $d)>{{ $name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="{{ $key }}_start" class="label">Start <span class="text-red-600">*</span></label>
        <input id="{{ $key }}_start" type="time" name="start_time" value="{{ $val('start_time') }}" @class(['input', 'input-error' => $errs->has('start_time')]) required>
    </div>
    <div>
        <label for="{{ $key }}_end" class="label">End <span class="text-red-600">*</span></label>
        <input id="{{ $key }}_end" type="time" name="end_time" value="{{ $val('end_time') }}" @class(['input', 'input-error' => $errs->has('end_time')]) required>
    </div>
</div>
@foreach ($errs->all() as $message)
    <p class="error">{{ $message }}</p>
@endforeach
