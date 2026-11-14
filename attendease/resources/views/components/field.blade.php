@props(['name', 'label', 'type' => 'text', 'value' => null, 'help' => null, 'required' => false, 'rules' => false])

@php
    $id = 'f_'.str_replace(['[', ']', '.'], '_', $name);
    // Password rules shown in the live checklist; they mirror Password::defaults() in AppServiceProvider.
    $passwordRules = [
        'length' => 'At least 8 characters',
        'lower' => 'A lowercase letter (a–z)',
        'upper' => 'An uppercase letter (A–Z)',
        'number' => 'A number (0–9)',
        'symbol' => 'A special character, like ! @ # $ % ?',
    ];
@endphp
<div {{ $attributes->only('class') }}>
    <label for="{{ $id }}" class="label">{{ $label }}@if ($required) <span class="text-red-600">*</span>@endif</label>
    @if ($type === 'select')
        <select id="{{ $id }}" name="{{ $name }}" @class(['input', 'input-error' => $errors->has($name)]) {{ $attributes->except('class') }} @required($required)>
            {{ $slot }}
        </select>
    @elseif ($type === 'textarea')
        <textarea id="{{ $id }}" name="{{ $name }}" @class(['input', 'input-error' => $errors->has($name)]) {{ $attributes->except('class') }} @required($required)>{{ old($name, $value) }}</textarea>
    @elseif ($type === 'password')
        {{-- Password with a show/hide toggle (never pre-filled from old input) --}}
        <div class="relative">
            <input id="{{ $id }}" name="{{ $name }}" type="password"
                @class(['input pr-20', 'input-error' => $errors->has($name)]) {{ $attributes->except('class') }} @required($required)
                @if ($rules) data-password-rules="{{ $id }}_rules" aria-describedby="{{ $id }}_rules" @endif>
            <button type="button" data-toggle-password="{{ $id }}" aria-controls="{{ $id }}" aria-pressed="false"
                class="absolute inset-y-1 right-1 flex items-center gap-1 rounded-md px-2.5 text-xs font-semibold text-slate-600 hover:bg-slate-100 hover:text-slate-900 focus:ring-2 focus:ring-brand-100 focus:outline-none">
                <svg data-eye class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12S6 5 12 5s9.5 7 9.5 7-3.5 7-9.5 7S2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg data-eye-off class="hidden h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18M10.6 5.1A9.7 9.7 0 0112 5c6 0 9.5 7 9.5 7a17 17 0 01-3.2 4.2M6.2 6.3C3.8 7.9 2.5 12 2.5 12S6 19 12 19a9.6 9.6 0 004.1-.9M9.9 9.9a3 3 0 004.2 4.2"/></svg>
                <span data-label>Show</span>
            </button>
        </div>
        @if ($rules)
            <ul id="{{ $id }}_rules" class="mt-2 grid gap-1 text-xs text-slate-500 sm:grid-cols-2" aria-live="polite">
                @foreach ($passwordRules as $rule => $text)
                    <li data-rule="{{ $rule }}" class="flex items-center gap-1.5">
                        <span data-mark class="grid h-4 w-4 shrink-0 place-items-center rounded-full border border-slate-300 text-[10px] leading-none" aria-hidden="true"></span>
                        <span>{{ $text }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    @else
        <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}"
            @if ($type !== 'file') value="{{ old($name, $value) }}" @endif
            @class(['input', 'input-error' => $errors->has($name)]) {{ $attributes->except('class') }} @required($required)>
    @endif
    @if ($help)<p class="help">{{ $help }}</p>@endif
    @if ($type === 'password')
        {{-- A password can break several rules at once: show every message. --}}
        @foreach ($errors->get($name) as $message)<p class="error">{{ $message }}</p>@endforeach
    @else
        @error($name)<p class="error">{{ $message }}</p>@enderror
    @endif
</div>
