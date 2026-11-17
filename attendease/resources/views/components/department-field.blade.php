@props(['user', 'help' => null])
@use('App\Support\StudentInfo')
{{-- FR-ADM-19: a department from the fixed list in config/students.php --}}
@php
    $departments = StudentInfo::departments();
    $current = old('department', $user->department);
    // A department typed before the list existed stays selectable, so saving doesn't wipe it.
    $legacy = filled($user->department) && ! array_key_exists($user->department, $departments) ? $user->department : null;
@endphp
<x-field name="department" label="Department" type="select" :help="$help">
    <option value="">—</option>
    @foreach ($departments as $code => $name)
        <option value="{{ $code }}" title="{{ $name }}" @selected($current === $code)>{{ $code }}</option>
    @endforeach
    @if ($legacy)
        <option value="{{ $legacy }}" @selected($current === $legacy)>{{ $legacy }} (old value, not in the list)</option>
    @endif
</x-field>
