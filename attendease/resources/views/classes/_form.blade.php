@php($c = $class ?? null)
<x-field name="subject" label="Subject" :value="$c?->subject" placeholder="ITE101" required />
<div class="grid gap-4 sm:grid-cols-2">
    <x-field name="year_level" label="Year level" :value="$c?->year_level" placeholder="1st Year" required />
    <x-field name="section" label="Section" :value="$c?->section" placeholder="BSIT-1A" required />
</div>
<div class="grid gap-4 sm:grid-cols-2">
    <x-field name="school_term" label="School term" :value="$c?->school_term" placeholder="2026-2027" required />
    <x-field name="semester" label="Semester" type="select" required>
        @foreach (\App\Models\SchoolClass::SEMESTERS as $sem)
            <option value="{{ $sem }}" @selected(old('semester', $c?->semester) === $sem)>{{ $sem }}</option>
        @endforeach
    </x-field>
</div>
<div class="grid gap-4 sm:grid-cols-2">
    <x-field name="semester_start" label="Semester start" type="date" :value="$c?->semester_start?->toDateString()" required />
    <x-field name="semester_end" label="Semester end" type="date" :value="$c?->semester_end?->toDateString()" required />
</div>
