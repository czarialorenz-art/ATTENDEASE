@props(['user', 'required' => false])
@use('App\Support\StudentInfo')
{{-- FR-ADM-10: course, year level and section from the fixed lists in config/students.php --}}
@php
    $course = old('course', array_key_exists((string) $user->course, StudentInfo::courses()) ? $user->course : null);
    $year = old('year_level', $user->year_level);
    $section = old('section', in_array($user->section, StudentInfo::sections(), true) ? $user->section : null);
@endphp
<div {{ $attributes->merge(['class' => 'grid gap-4 sm:grid-cols-3']) }}>
    @if ($user->exists && $user->isStudent() && $user->needsInfoUpdate())
        <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 sm:col-span-3" role="status">
            <strong>Pick the right values below.</strong> Old typed values: {{ $user->legacyInfo() }}.
        </p>
    @endif
    <x-field name="course" label="Course" type="select" :required="$required">
        <option value="">—</option>
        @foreach (StudentInfo::courses() as $code => $name)
            <option value="{{ $code }}" @selected($course === $code) title="{{ $name }}">{{ $code }}</option>
        @endforeach
    </x-field>
    <x-field name="year_level" label="Year level" type="select" :required="$required">
        <option value="">—</option>
        @foreach (StudentInfo::yearLevels() as $level)
            <option value="{{ $level }}" @selected((string) $year === (string) $level)>{{ StudentInfo::yearLabel($level) }}</option>
        @endforeach
    </x-field>
    <x-field name="section" label="Section" type="select" :required="$required">
        <option value="">—</option>
        @foreach (StudentInfo::sections() as $letter)
            <option value="{{ $letter }}" @selected($section === $letter)>Section {{ $letter }}</option>
        @endforeach
    </x-field>
</div>
